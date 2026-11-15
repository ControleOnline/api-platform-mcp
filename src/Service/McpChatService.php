<?php

declare(strict_types=1);

namespace ControleOnline\Service;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\Task;
use ControleOnline\Entity\TaskInteration;
use ControleOnline\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * AI conversations persisted as Task + TaskInteration, processed by the McpChat queue.
 *
 * - Internal chat (/ai, employee): Task ia with announce {"channel":"mcp"}; MCP business tools run
 *   as that employee; replies are private and pushed only to the asking device.
 * - Customer conversation (WhatsApp or e-mail): while tasks.ai_agent is set
 *   the AI answers the customer (public reply -> TaskInterationService::notifyClient delivers it),
 *   classifies the ticket and hands off to relationship (CRM) or support. Employees answer the
 *   customer themselves in CRM/Support; an employee reply takes the conversation from the AI.
 * - assign(): an employee puts the AI on a CRM/support ticket; the AI makes the first contact.
 */
class McpChatService
{
    public const CONFIG_KEY = McpChatProviderConfig::CONFIG_KEY;
    private const HISTORY_LIMIT = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly McpChatProviderConfig $providers,
        private readonly McpChatLlmClient $llm,
        private readonly McpChatToolbox $toolbox,
        private readonly McpChatPrompts $prompts,
        private readonly McpChatTickets $tickets,
        private readonly ?McpChatQueue $queue = null,
        private readonly ?McpCompanyScopeProviderInterface $companyScopeProvider = null,
        private readonly ?ConversationRealtimeNotifier $realtime = null,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * POST /mcp/chat — internal chat of an employee of the company, with that company's AI.
     * Customers never use /ai: they reach the company AI through its WhatsApp / e-mail connections.
     *
     * @param array{provider?: string, task_id?: int, message: string, company_id?: int} $input
     * @return array<string, mixed>
     */
    public function chat(array $input): array
    {
        $message = trim((string) ($input['message'] ?? ''));
        if ($message === '') {
            throw new \InvalidArgumentException('message is required');
        }
        $user = $this->currentPeople();
        // /ai belongs to the company's employees and uses that company's AI; outsiders get a 400.
        $company = $this->resolveCompany(isset($input['company_id']) ? (int) $input['company_id'] : null);
        $taskId = isset($input['task_id']) ? (int) $input['task_id'] : 0;

        // A ticket the user opened with the platform, or a company without its own AI: ticket mode.
        $ticket = $taskId ? $this->tickets->findOwn($taskId, $user) : null;
        $resolved = $ticket ? null : $this->providers->resolve($company, $input['provider'] ?? null);
        if ($ticket || $resolved === null) {
            return $this->ticketMessage($ticket, $user, $company, (string) ($input['type'] ?? 'support'), $message);
        }
        [$provider, $config] = $resolved;

        $task = $taskId
            ? $this->ownInternalChat($taskId, $user, $company)
            : $this->newInternalChat($user, $company, $provider, (string) $config['model'], $message);
        $channel = McpChatToolbox::CHANNEL_WEB;
        $visibility = 'private';

        $interaction = $this->addInteraction($task, $user, $message, $visibility, 'pending');
        $this->em->flush();
        $integrationId = $this->enqueue($task, $company, $channel, 'customer', $interaction->getId(), $provider);

        return [
            'task_id' => $task->getId(),
            'interaction_id' => $interaction->getId(),
            'integration_id' => $integrationId,
            'type' => $task->getType(),
            'provider' => $provider,
            'status' => 'pending',
            'message' => null,
            'async' => true,
        ];
    }

    /**
     * Message on a ticket to the platform (opened now if needed). Humans of the platform answer in
     * CRM / Support; if they put their AI on the ticket, it answers with the platform's tokens.
     *
     * @return array<string, mixed>
     */
    private function ticketMessage(?Task $ticket, People $user, People $company, string $type, string $message): array
    {
        $ticket ??= $this->tickets->open($user, $company, $type, $message);
        $platform = $ticket->getProvider();
        $withAi = (bool) $ticket->getAiAgent();
        $interaction = $this->addInteraction($ticket, $user, $message, 'public', $withAi ? 'pending' : 'done');
        if (method_exists($ticket, 'setAlterDate')) {
            $ticket->setAlterDate(new \DateTime('now'));
        }
        $this->em->flush();

        if ($withAi) {
            $this->enqueue($ticket, $platform, McpChatToolbox::CHANNEL_CUSTOMER, 'customer', $interaction->getId(), $ticket->getAiAgent());
        } else {
            $this->notify($platform, $ticket, null, ['event' => 'conversation_message', 'status' => 'received', 'interaction_id' => $interaction->getId()]);
        }

        return [
            'task_id' => $ticket->getId(),
            'interaction_id' => $interaction->getId(),
            'mode' => 'ticket',
            'type' => $ticket->getType(),
            'status' => $withAi ? 'pending' : 'done',
            'async' => $withAi,
        ];
    }

    /**
     * An employee puts the AI on a customer ticket (CRM/support/ia). Optional instructions are kept
     * as a private note; the AI then writes to the customer through the ticket's connection.
     *
     * @return array{task_id: int, ai_agent: string}
     */
    public function assign(int $taskId, ?string $instructions, ?string $provider = null, ?int $companyId = null): array
    {
        $company = $this->resolveCompany($companyId);
        $task = $this->customerTicket($taskId, $company);
        if ($this->providers->resolve($company, $provider) === null) {
            throw new \InvalidArgumentException('AI provider not configured or disabled for this company');
        }
        $task->setAiAgent($provider ?: 'auto');
        $this->tickets->addCustomerContacts($task);
        $instructions = trim((string) $instructions);
        $this->toolbox->note($task, $this->currentPeople(), '[Instruções para a IA] ' . ($instructions !== '' ? $instructions : 'Fazer o primeiro contato.'));
        $this->enqueue($task, $company, McpChatToolbox::CHANNEL_CUSTOMER, 'assign', 0, $task->getAiAgent());

        return ['task_id' => (int) $task->getId(), 'ai_agent' => (string) $task->getAiAgent()];
    }

    /**
     * An employee takes a ticket from the AI (optionally moving ia -> relationship|support).
     *
     * @return array{task_id: int, type: string}
     */
    public function humanHandoff(int $taskId, string $type, ?int $companyId = null): array
    {
        $company = $this->resolveCompany($companyId);
        $task = $this->customerTicket($taskId, $company);
        $this->toolbox->handoff($task, $company, [
            'type' => $type,
            'summary' => 'Assumido por ' . trim((string) $this->currentPeople()->getName()),
        ]);
        $this->notify($company, $task, null, ['status' => 'done', 'handoff' => $task->getType()]);

        return ['task_id' => (int) $task->getId(), 'type' => (string) $task->getType()];
    }

    /** Integration queue handler (queueName=McpChat). */
    public function integrate(Integration $integration): void
    {
        $data = json_decode((string) $integration->getBody(), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid McpChat integration body');
        }
        $this->processQueuedChat($data, $integration->getUser());
    }

    /** @param array<string, mixed> $data */
    public function processQueuedChat(array $data, ?User $asUser = null): void
    {
        $task = $this->em->getRepository(Task::class)->find((int) ($data['task_id'] ?? 0));
        if (!$task instanceof Task) {
            throw new \RuntimeException('task not found for McpChat job');
        }
        $channel = $this->isInternalChat($task) ? McpChatToolbox::CHANNEL_WEB : McpChatToolbox::CHANNEL_CUSTOMER;
        $device = trim((string) ($data['device'] ?? '')) ?: null;
        $interactionId = (int) ($data['interaction_id'] ?? 0);
        $company = $this->resolveCompany(isset($data['company_id']) ? (int) $data['company_id'] : null, skipUserScope: true);
        $platform = isset($data['platform_id']) ? $this->em->getRepository(People::class)->find((int) $data['platform_id']) : null;
        $this->tickets->usePlatform($platform instanceof People ? $platform : null);
        $userInteraction = $interactionId ? $this->em->getRepository(TaskInteration::class)->find($interactionId) : null;
        $userInteraction = $userInteraction instanceof TaskInteration ? $userInteraction : null;

        // Customer conversations are answered only while an AI is assigned (a person may have taken over).
        if ($channel === McpChatToolbox::CHANNEL_CUSTOMER && !$task->getAiAgent()) {
            $this->setStatus($userInteraction, 'done');

            return;
        }

        $toolsUsed = [];
        $this->setStatus($userInteraction, 'processing');
        $this->notify($company, $task, $device, ['status' => 'processing', 'user_interaction_id' => $interactionId]);

        try {
            $candidates = $this->providers->candidates($company, isset($data['provider']) ? (string) $data['provider'] : null);
            if ($candidates === []) {
                throw new \RuntimeException('AI provider not configured for this company');
            }

            $answer = null;
            foreach ($candidates as $i => [$provider, $config]) {
                try {
                    $answer = $this->asUser($channel === McpChatToolbox::CHANNEL_WEB ? $asUser : null, fn () => $this->llm->run(
                        $provider,
                        $config,
                        $this->prompts->system($channel, (string) ($data['trigger'] ?? 'customer'), $company, $task, (string) ($config['system_prompt'] ?? '')),
                        $this->prompts->history($this->recentInteractions($task), $company, $task, $channel),
                        $this->toolbox->definitions($channel, $task),
                        $this->keepingConnection($this->toolbox->executor($channel, $task, $company), $toolsUsed),
                    ));
                    break;
                } catch (McpChatProviderUnavailableException $e) {
                    // Next connected AI of the same company, only while no tool ran (tools may have side effects).
                    if ($toolsUsed !== [] || $i === count($candidates) - 1) {
                        throw $e;
                    }
                    $this->logger?->warning('McpChat provider unavailable, trying the next one: ' . $e->getMessage(), ['provider' => $provider]);
                    $this->ensureConnection();
                }
            }
            // The LLM can take longer than MySQL's wait_timeout (15 s on dev) with no query in between.
            $this->ensureConnection();

            // Customer conversations: public, so notifyClient delivers it through the ticket's connection.
            // Customer conversations and /ai chats escalated to a ticket during this run are public.
            $visibility = $channel === McpChatToolbox::CHANNEL_CUSTOMER || !$this->isInternalChat($task) ? 'public' : 'private';
            $assistant = $this->addInteraction($task, $company, $answer, $visibility, 'done');
            $this->setStatus($userInteraction, 'done', flush: false);
            if (method_exists($task, 'setAlterDate')) {
                $task->setAlterDate(new \DateTime('now'));
            }
            $this->em->flush();

            if ($channel === McpChatToolbox::CHANNEL_WEB && !$this->isInternalChat($task)) {
                // Escalated: the platform team sees the new ticket live in CRM / Support.
                $this->notify($task->getProvider(), $task, null, ['event' => 'conversation_message', 'status' => 'received']);
            }
            $this->notify($company, $task, $device, [
                'status' => 'done',
                'interaction_id' => $assistant->getId(),
                'user_interaction_id' => $interactionId,
                'handoff' => $task->getAiAgent() ? null : $task->getType(),
            ]);
        } catch (\Throwable $e) {
            $this->ensureConnection();
            $this->logger?->error('McpChat failed: ' . $e->getMessage(), [
                'task' => $task->getId(),
                'interaction' => $interactionId,
                'provider' => $data['provider'] ?? null,
                'company' => $company->getId(),
                'tools' => $toolsUsed,
            ]);
            $note = null;
            try {
                // Persisted so the failure is visible after a reload, not only in the WebSocket event.
                // /ai (internal chat): a short message to the user. Customer conversations: a private
                // note for the team only — the customer never sees errors.
                $note = $channel === McpChatToolbox::CHANNEL_WEB
                    ? $this->addInteraction($task, $company, 'Não consegui responder agora. Tente novamente em instantes.', 'private', 'error')
                    : $this->addInteraction($task, $company, '[Interno] Falha na resposta automática: ' . mb_substr($e->getMessage(), 0, 300), 'private', 'error');
                $this->setStatus($userInteraction, 'error', flush: false);
                $this->em->flush();
            } catch (\Throwable) {
                $note = null;
            }
            $this->notify($company, $task, $device, [
                'status' => 'error',
                'interaction_id' => $note?->getId(),
                'user_interaction_id' => $interactionId,
                'body' => mb_substr($e->getMessage(), 0, 300),
            ]);
            // Not rethrown: a retry would call the LLM again and duplicate replies.
        }
    }

    /** @return array{default: string} */
    public function setDefaultProvider(string $provider, ?int $companyId = null): array
    {
        return $this->providers->setDefault($this->resolveCompany($companyId), $provider);
    }

    public function defaultProvider(?int $companyId = null): ?string
    {
        return $this->providers->resolve($this->resolveCompany($companyId), null)[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function listConfiguredProviders(?int $companyId = null): array
    {
        return $this->providers->listConfigured($this->resolveCompany($companyId));
    }

    /** @return array<string, mixed> */
    public function getProvidersConfigForUi(?int $companyId = null): array
    {
        return $this->providers->forUi($this->resolveCompany($companyId));
    }

    /** @return array{config_id: int} */
    public function saveProvidersConfig(array $providers, ?int $companyId = null): array
    {
        return $this->providers->save($this->resolveCompany($companyId), $providers);
    }

    public function isInternalChat(?Task $task): bool
    {
        $announce = $task?->getAnnounce(true) ?? [];

        return $task?->getType() === 'ia' && !array_is_list($announce) && ($announce['channel'] ?? '') === 'mcp';
    }

    private function enqueue(Task $task, People $company, string $channel, string $trigger, int $interactionId, ?string $provider): ?int
    {
        $auth = $this->security->getUser();
        $job = [
            'action' => 'mcp_chat',
            'channel' => $channel,
            'trigger' => $trigger,
            'task_id' => $task->getId(),
            'interaction_id' => $interactionId,
            'provider' => $provider !== 'auto' ? $provider : null,
            'company_id' => $company->getId(),
            'platform_id' => $this->tickets->platformId(),
            'device' => trim((string) $this->requestStack?->getCurrentRequest()?->headers->get('device', '')),
        ];
        if ($this->queue === null) {
            $this->processQueuedChat($job); // tests / no queue wired

            return null;
        }

        // Runs right after the response; the worker is only the fallback.
        return $this->queue->add($job, $company, $auth instanceof User ? $auth : null);
    }

    /** Run as the employee who asked so MCP tools apply that user's company scope. */
    private function asUser(?User $user, callable $fn): mixed
    {
        if ($user === null || $this->tokenStorage === null) {
            return $fn();
        }
        $previous = $this->tokenStorage->getToken();
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'mcp_chat', $user->getRoles()));
        // After the response (kernel.terminate) or in the worker there is no request, and the MCP read
        // queries need one (they apply the API securityFilter through its query string).
        $pushed = false;
        if ($this->requestStack !== null && $this->requestStack->getCurrentRequest() === null) {
            $this->requestStack->push(Request::create('/mcp/chat/process', 'GET'));
            $pushed = true;
        }
        try {
            return $fn();
        } finally {
            if ($pushed) {
                $this->requestStack->pop();
            }
            $this->tokenStorage->setToken($previous);
        }
    }

    /** Internal chats: the asking device only. Customer conversations: company MANAGER devices (+ asking device). */
    private function notify(People $company, Task $task, ?string $device, array $event): void
    {
        $event += ['event' => 'mcp_chat_reply', 'channel' => $this->isInternalChat($task) ? 'web' : 'customer'];
        try {
            if ($this->isInternalChat($task)) {
                if ($device) {
                    $this->realtime?->notify($company, $task, $event, $device);
                }
                return;
            }
            $this->realtime?->notify($company, $task, $event);
            if ($device) {
                $this->realtime?->notify($company, $task, $event, $device);
            }
        } catch (\Throwable) {
            // Realtime is best effort; the front reloads on its own fallback timer.
        }
    }

    /** @return list<TaskInteration> oldest first */
    private function recentInteractions(Task $task): array
    {
        $rows = $this->em->getRepository(TaskInteration::class)->findBy(
            ['task' => $task, 'type' => 'comment'],
            ['id' => 'DESC'],
            self::HISTORY_LIMIT,
        );

        return array_reverse($rows);
    }

    /** Reconnects when the server dropped an idle connection (DBAL reconnects lazily after close()). */
    private function ensureConnection(): void
    {
        $connection = $this->em->getConnection();
        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            $connection->close();
        }
    }

    /** Tools run between LLM calls, after an idle period of possibly more than wait_timeout. */
    /** @param list<string> $used tool names, in call order (logged when the run fails) */
    private function keepingConnection(callable $executor, array &$used): callable
    {
        return function (string $name, array $args) use ($executor, &$used): string {
            $used[] = $name;
            $this->ensureConnection();

            return $executor($name, $args);
        };
    }

    private function setStatus(?TaskInteration $interaction, string $status, bool $flush = true): void
    {
        if ($interaction === null) {
            return;
        }
        $interaction->setStatus($status);
        if ($flush) {
            $this->em->flush();
        }
    }

    private function currentPeople(): People
    {
        $auth = $this->security->getUser();
        $user = $auth instanceof User ? $auth->getPeople() : ($auth instanceof People ? $auth : null);
        if (!$user instanceof People) {
            throw new \RuntimeException('Authenticated People user required');
        }

        return $user;
    }

    private function isEmployee(?int $companyId): bool
    {
        $allowed = array_map('intval', array_column($this->companyScopeProvider?->listForCurrentUser() ?? [], 'id'));

        return $companyId ? in_array($companyId, $allowed, true) : $allowed !== [];
    }

    private function resolveCompany(?int $companyId, bool $skipUserScope = false): People
    {
        if ($companyId !== null && $companyId > 0) {
            $company = $this->em->getRepository(People::class)->find($companyId);
            if (!$company instanceof People) {
                throw new \InvalidArgumentException('company_id not found');
            }
            if (!$skipUserScope && !$this->isEmployee($companyId)) {
                throw new \InvalidArgumentException('company_id not in user scope');
            }

            return $company;
        }
        $first = $this->companyScopeProvider?->listForCurrentUser()[0]['id'] ?? null;
        $company = $first ? $this->em->getRepository(People::class)->find((int) $first) : null;
        if (!$company instanceof People) {
            throw new \RuntimeException('No accessible company for current user');
        }

        return $company;
    }

    private function findTask(int $taskId): ?Task
    {
        $task = $this->em->getRepository(Task::class)->find($taskId);

        return $task instanceof Task ? $task : null;
    }

    private function inCompany(?Task $task, People $company): Task
    {
        if (!$task || (int) $task->getProvider()?->getId() !== (int) $company->getId()) {
            throw new \InvalidArgumentException('task_id not in company scope');
        }

        return $task;
    }

    /** Internal chats are private to their author. */
    private function ownInternalChat(int $taskId, People $user, People $company): Task
    {
        $task = $this->inCompany($this->findTask($taskId), $company);
        if (!$this->isInternalChat($task)) {
            throw new \InvalidArgumentException('Customer conversations are answered in CRM / Support');
        }
        if ((int) $task->getRegisteredBy()?->getId() !== (int) $user->getId()) {
            throw new \InvalidArgumentException('task_id not owned by current user');
        }

        return $task;
    }

    private function customerTicket(int $taskId, People $company): Task
    {
        $task = $this->inCompany($this->findTask($taskId), $company);
        if ($this->isInternalChat($task) || !in_array($task->getType(), ['ia', 'relationship', 'support'], true)) {
            throw new \InvalidArgumentException('task is not a customer conversation');
        }

        return $task;
    }

    private function newInternalChat(People $user, People $company, string $provider, string $model, string $firstMessage): Task
    {
        $task = $this->newTask('ia', $user, $company, $firstMessage);
        $task->setClient($user);
        $task->setAnnounce(['channel' => 'mcp', 'ai_provider' => $provider, 'model' => $model]);
        $this->em->flush();

        return $task;
    }

    private function newTask(string $type, People $user, People $company, string $firstMessage): Task
    {
        $status = $this->em->getRepository(Status::class)->findOneBy(['context' => $type, 'status' => 'open']);
        if (!$status instanceof Status) {
            throw new \RuntimeException("Status context={$type} open not seeded");
        }
        $task = new Task();
        $task->setType($type);
        $task->setName(mb_substr($firstMessage, 0, 50));
        $task->setRegisteredBy($user);
        $task->setProvider($company);
        $task->setTaskStatus($status);
        $this->em->persist($task);

        return $task;
    }

    private function addInteraction(Task $task, People $by, string $body, string $visibility, string $status): TaskInteration
    {
        $row = new TaskInteration();
        $row->setTask($task);
        $row->setRegisteredBy($by);
        $row->setType('comment');
        $row->setBody($body);
        $row->setVisibility($visibility);
        $row->setStatus($status);
        $row->setRead(0);
        $row->setNotified(0);
        $this->em->persist($row);

        return $row;
    }
}
