<?php

declare(strict_types=1);

namespace ControleOnline\Service;

use ControleOnline\Entity\People;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\Task;
use ControleOnline\Entity\TaskInteration;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tools exposed to the LLM per channel.
 *
 * - web (internal /ai chat of an employee): read-only MCP business tools, run as that employee.
 * - customer (conversation with a customer over WhatsApp, e-mail or /ai): NO business data.
 *   classify_task keeps the CRM/support ticket right; handoff_to_human turns the conversation
 *   into relationship (sales/CRM) or support and removes the AI.
 */
class McpChatToolbox
{
    public const CHANNEL_WEB = 'web';
    public const CHANNEL_CUSTOMER = 'customer';
    public const HANDOFF_TYPES = ['relationship', 'support'];

    /** Mutating MCP tools are never offered to the chat. */
    private const BLOCKED_TOOLS = [
        'write_business_data', 'linkedin_create_post', 'linkedin_create_organization_post', 'mercadolivre_import_products',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly McpServerService $mcpServer,
        private readonly StatusService $statusService,
        private readonly ?McpChatTickets $tickets = null,
    ) {
    }

    /** @return list<array{name: string, description: string, schema: array<string, mixed>}> */
    public function definitions(string $channel, ?Task $task = null): array
    {
        if ($channel === self::CHANNEL_CUSTOMER) {
            return [$this->handoffDefinition($task), $this->classifyDefinition($task)];
        }

        $listed = $this->mcpServer->handle(['jsonrpc' => '2.0', 'id' => 'tools', 'method' => 'tools/list']);
        $tools = [];
        foreach ((array) ($listed['result']['tools'] ?? []) as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name === '' || in_array($name, self::BLOCKED_TOOLS, true)) {
                continue;
            }
            $tools[] = [
                'name' => $name,
                'description' => (string) ($tool['description'] ?? $name),
                'schema' => is_array($tool['inputSchema'] ?? null)
                    ? $tool['inputSchema']
                    : ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }

        if ($this->tickets !== null) {
            $tools[] = $this->escalateDefinition();
        }

        return $tools;
    }

    /** @return callable(string, array<string, mixed>): string */
    public function executor(string $channel, Task $task, People $company): callable
    {
        return function (string $name, array $args) use ($channel, $task, $company): string {
            if ($channel === self::CHANNEL_CUSTOMER) {
                return match ($name) {
                    'handoff_to_human' => $this->handoff($task, $company, $args),
                    'classify_task' => $this->classify($task, $args),
                    default => throw new \InvalidArgumentException("Tool not available: {$name}"),
                };
            }
            if ($name === 'handoff_to_human' && $this->tickets !== null) {
                return $this->tickets->escalate($task, $company, (string) ($args['type'] ?? ''), (string) ($args['summary'] ?? ''));
            }
            if (in_array($name, self::BLOCKED_TOOLS, true)) {
                throw new \InvalidArgumentException("Tool not available in chat: {$name}");
            }

            return $this->callMcp($name, $args);
        };
    }

    /**
     * Hands the conversation to humans: ia -> relationship|support; the AI is unassigned.
     * Used by the AI tool and when an employee takes over.
     */
    public function handoff(Task $task, People $company, array $args): string
    {
        $type = strtolower(trim((string) ($args['type'] ?? '')));
        if ($type === '' && in_array($task->getType(), self::HANDOFF_TYPES, true)) {
            $type = (string) $task->getType();
        }
        if (!in_array($type, self::HANDOFF_TYPES, true)) {
            throw new \InvalidArgumentException('type must be relationship or support');
        }
        if ($task->getType() !== $type) {
            $task->setType($type);
            $task->setTaskStatus($this->statusService->discoveryStatus('open', 'open', $type));
        }
        if (method_exists($task, 'setAiAgent')) {
            $task->setAiAgent(null);
        }
        $this->touch($task);
        $label = $type === 'support' ? 'Suporte' : 'Comercial';
        $this->note($task, $company, "[IA → {$label}] " . trim((string) ($args['summary'] ?? '')));

        return "OK: conversation is now with the {$label} team. Tell the customer, briefly, that a person will continue here.";
    }

    private function classify(Task $task, array $args): string
    {
        $changes = [];
        $statusName = trim((string) ($args['status'] ?? ''));
        if ($statusName !== '') {
            $status = $this->em->getRepository(Status::class)->findOneBy(['context' => $task->getType(), 'status' => $statusName]);
            if (!$status instanceof Status) {
                throw new \InvalidArgumentException('Unknown status for ' . $task->getType() . ': ' . $statusName);
            }
            $task->setTaskStatus($status);
            $changes[] = "status={$statusName}";
        }
        $title = trim((string) ($args['title'] ?? ''));
        if ($title !== '') {
            $task->setName(mb_substr($title, 0, 50));
            $changes[] = 'title';
        }
        if ($changes === []) {
            return 'Nothing to change.';
        }
        $this->touch($task);
        $this->em->flush();

        return 'OK: ' . implode(', ', $changes);
    }

    private function callMcp(string $name, array $args): string
    {
        $response = $this->mcpServer->handle([
            'jsonrpc' => '2.0',
            'id' => 'call',
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $args === [] ? new \stdClass() : $args],
        ]);
        if (isset($response['error'])) {
            throw new \RuntimeException((string) ($response['error']['message'] ?? 'MCP tool error'));
        }
        $texts = [];
        foreach ((array) ($response['result']['content'] ?? []) as $part) {
            $texts[] = (string) ($part['text'] ?? '');
        }

        // Keep tool output bounded so long datasets do not blow the context window.
        return mb_substr(implode("\n", $texts), 0, 20000);
    }

    /** Private note for employees; never sent to the customer. */
    public function note(Task $task, People $by, string $body): void
    {
        $note = new TaskInteration();
        $note->setTask($task);
        $note->setRegisteredBy($by);
        $note->setType('comment');
        $note->setVisibility('private');
        $note->setBody($body);
        $note->setStatus('done');
        $note->setRead(0);
        $note->setNotified(0);
        $this->em->persist($note);
        $this->em->flush();
    }

    private function touch(Task $task): void
    {
        if (method_exists($task, 'setAlterDate')) {
            $task->setAlterDate(new \DateTime('now'));
        }
    }

    /** @return list<string> */
    private function statusNames(?Task $task): array
    {
        if (!$task || $task->getType() === 'ia') {
            return [];
        }
        $names = [];
        foreach ($this->em->getRepository(Status::class)->findBy(['context' => $task->getType()]) as $status) {
            $names[] = (string) $status->getStatus();
        }

        return array_values(array_unique($names));
    }

    private function classifyDefinition(?Task $task): array
    {
        $statuses = $this->statusNames($task);
        $properties = ['title' => ['type' => 'string', 'description' => 'Short title for the ticket (max 50 chars).']];
        if ($statuses !== []) {
            $properties['status'] = ['type' => 'string', 'enum' => $statuses, 'description' => 'Current stage of this ticket.'];
        }

        return [
            'name' => 'classify_task',
            'description' => 'Keep the ticket classified as the conversation evolves: a clear title and, for '
                . 'CRM/support tickets, the stage that matches what has happened so far.',
            'schema' => ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false],
        ];
    }

    /** /ai: the company's AI could not solve it -> support or sales ticket with the platform team. */
    private function escalateDefinition(): array
    {
        return [
            'name' => 'handoff_to_human',
            'description' => 'Open a ticket with the platform team when you cannot solve the request with the data and tools '
                . 'you have, or when the user asks for a person. Use "support" for problems, errors, access and how-to that '
                . 'you could not resolve; use "relationship" for commercial matters (plans, prices, contracts, new features). '
                . 'The conversation so far goes with the ticket.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => self::HANDOFF_TYPES],
                    'summary' => ['type' => 'string', 'description' => 'What the user needs and what you already tried.'],
                ],
                'required' => ['type', 'summary'],
                'additionalProperties' => false,
            ],
        ];
    }

    private function handoffDefinition(?Task $task): array
    {
        $needsType = !$task || $task->getType() === 'ia';

        return [
            'name' => 'handoff_to_human',
            'description' => 'Hand this conversation to a person and stop answering. '
                . ($needsType ? 'Use type "relationship" for sales (quotes, prices, buying, plans) and "support" for '
                    . 'help with the product, access or billing of existing customers. ' : '')
                . 'Call it when the matter needs a human or the customer asks for one, after collecting what '
                . 'the person needs (name, company, details of the request).',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => self::HANDOFF_TYPES],
                    'summary' => ['type' => 'string', 'description' => 'Everything collected so far, for the employee.'],
                ],
                'required' => $needsType ? ['type', 'summary'] : ['summary'],
                'additionalProperties' => false,
            ],
        ];
    }
}
