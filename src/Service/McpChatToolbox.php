<?php

declare(strict_types=1);

namespace ControleOnline\Service;

use ControleOnline\Entity\People;
use ControleOnline\Entity\Task;
use ControleOnline\Entity\TaskInteration;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tools exposed to the LLM per channel.
 *
 * - web (/ai, internal user): read-only MCP business tools, executed as the asking user.
 * - whatsapp (external customer): NO business data. Only handoff_to_human, which turns
 *   the "ia" task into relationship (sales/CRM) or support (analyst) so a human takes over.
 */
class McpChatToolbox
{
    public const CHANNEL_WEB = 'web';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const HANDOFF_TYPES = ['relationship', 'support'];

    /** Mutating MCP tools are never offered to the chat. */
    private const BLOCKED_TOOLS = [
        'write_business_data', 'linkedin_create_post', 'linkedin_create_organization_post', 'mercadolivre_import_products',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly McpServerService $mcpServer,
        private readonly StatusService $statusService,
    ) {
    }

    /** @return list<array{name: string, description: string, schema: array<string, mixed>}> */
    public function definitions(string $channel): array
    {
        if ($channel === self::CHANNEL_WHATSAPP) {
            return [$this->handoffDefinition()];
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

        return $tools;
    }

    /**
     * @return callable(string, array<string, mixed>): string
     */
    public function executor(string $channel, Task $task, People $company): callable
    {
        return function (string $name, array $args) use ($channel, $task, $company): string {
            if ($channel === self::CHANNEL_WHATSAPP) {
                if ($name !== 'handoff_to_human') {
                    throw new \InvalidArgumentException("Tool not available: {$name}");
                }

                return $this->handoff($task, $company, $args);
            }
            if (in_array($name, self::BLOCKED_TOOLS, true)) {
                throw new \InvalidArgumentException("Tool not available in chat: {$name}");
            }

            return $this->callMcp($name, $args);
        };
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

    /**
     * Turns an "ia" conversation into relationship/support. Used by the AI tool and by
     * an attendant taking over from /ai.
     */
    public function handoff(Task $task, People $company, array $args): string
    {
        $type = strtolower(trim((string) ($args['type'] ?? '')));
        if (!in_array($type, self::HANDOFF_TYPES, true)) {
            throw new \InvalidArgumentException('type must be relationship or support');
        }
        if ($task->getType() !== 'ia') {
            return 'Conversation is already with a human team.';
        }
        $summary = trim((string) ($args['summary'] ?? ''));

        $task->setType($type);
        $task->setTaskStatus($this->statusService->discoveryStatus('open', 'open', $type));
        if (method_exists($task, 'setAlterDate')) {
            $task->setAlterDate(new \DateTime('now'));
        }

        // Private note for the attendant; never sent to the customer.
        $note = new TaskInteration();
        $note->setTask($task);
        $note->setRegisteredBy($company);
        $note->setType('comment');
        $note->setVisibility('private');
        $note->setBody('[Handoff IA → ' . ($type === 'support' ? 'Suporte' : 'Comercial') . '] ' . $summary);
        $note->setStatus('done');
        $note->setRead(0);
        $note->setNotified(0);
        $this->em->persist($note);
        $this->em->flush();

        return "OK: conversation transferred to the {$type} team. Tell the customer, briefly, that a person will continue the conversation here. Do not promise times you do not know.";
    }

    /** @return array{name: string, description: string, schema: array<string, mixed>} */
    private function handoffDefinition(): array
    {
        return [
            'name' => 'handoff_to_human',
            'description' => 'Transfer this WhatsApp conversation to a human. Use type "relationship" for sales '
                . '(quotes, prices, buying, plans, new customers) and "support" for help with the product, '
                . 'bugs, access or billing problems of existing customers. Call it once you have collected '
                . 'what the human needs (name, company, what they want) or when the customer asks for a person.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => self::HANDOFF_TYPES],
                    'summary' => [
                        'type' => 'string',
                        'description' => 'What the customer wants and every detail collected so far, for the attendant.',
                    ],
                ],
                'required' => ['type', 'summary'],
                'additionalProperties' => false,
            ],
        ];
    }
}
