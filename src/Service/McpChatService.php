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
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * MCP chat sessions persisted as Task(type=ia) + TaskInteration.
 *
 * Phase 1 (HTTP, /ai): persist the user turn and queue Integration(McpChat).
 * Phase 2 (worker): run the LLM tool loop, persist the reply, push a websocket event.
 *
 * Channels:
 * - web: internal user; tools run as that user (the worker impersonates Integration.user);
 *   the reply is private and pushed only to the device that asked.
 * - whatsapp: external customer (queued by WhatsAppCloudService); the reply is public, so
 *   TaskInterationService::notifyClient sends it to WhatsApp. Once the task is handed off
 *   (relationship/support) the AI stops answering.
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
        private readonly ?McpCompanyScopeProviderInterface $companyScopeProvider = null,
        private readonly ?IntegrationService $integrationService = null,
        private readonly ?ConversationRealtimeNotifier $realtime = null,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * @param array{provider?: string, task_id?: int, message: string, company_id?: int} $input
     * @return array<string, mixed>
     */
    public function chat(array $input): array
    {
        $message = trim((string) ($input['message'] ?? ''));
        if ($message === '') {
            throw new \InvalidArgumentException('message is required');
        }
        $auth = $this->security->getUser();
        $user = $auth instanceof User ? $auth->getPeople() : ($auth instanceof People ? $auth : null);
        if (!$user instanceof People) {
            throw new \RuntimeException('Authenticated People user required');
        }

        $company = $this->resolveCompany($input['company_id'] ?? null);
        $resolved = $this->providers->resolve($company, $input['provider'] ?? null);
        if ($resolved === null) {
            throw new \InvalidArgumentException('AI provider not configured or disabled for this company');
        }
        [$provider, $config] = $resolved;

        $task = $this->resolveOrCreateTask(
            isset($input['task_id']) ? (int) $input['task_id'] : null,
            $user,
            $company,
            $provider,
            (string) ($config['model'] ?? ''),
            $message,
        );
        $interaction = $this->addInteraction($task, $user, $message, 'private', 'pending');
        $this->em->flush();

        $job = [
            'action' => 'mcp_chat',
            'channel' => McpChatToolbox::CHANNEL_WEB,
            'task_id' => $task->getId(),
            'interaction_id' => $interaction->getId(),
            'provider' => $provider,
            'company_id' => $company->getId(),
            'user_id' => $user->getId(),
            'device' => trim((string) $this->requestStack?->getCurrentRequest()?->headers->get('device', '')),
        ];

        $integrationId = null;
        if ($this->integrationService !== null) {
            $integrationId = $this->integrationService->addIntegration(
                (string) json_encode($job, JSON_UNESCAPED_UNICODE),
                'McpChat',
                null,
                $auth instanceof User ? $auth : null,
                $company,
            )->getId();
        } else {
            $this->processQueuedChat($job); // tests / no queue wired
        }

        return [
            'task_id' => $task->getId(),
            'interaction_id' => $interaction->getId(),
            'integration_id' => $integrationId,
            'type' => 'ia',
            'provider' => $provider,
            'status' => 'pending',
            'message' => null,
            'async' => true,
        ];
    }

    /**
     * Integration queue handler (queueName=McpChat).
     */
    public function integrate(Integration $integration): void
    {
        $data = json_decode((string) $integration->getBody(), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid McpChat integration body');
        }
        $this->processQueuedChat($data, $integration->getUser());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function processQueuedChat(array $data, ?User $asUser = null): void
    {
        $channel = ($data['channel'] ?? '') === McpChatToolbox::CHANNEL_WHATSAPP
            ? McpChatToolbox::CHANNEL_WHATSAPP
            : McpChatToolbox::CHANNEL_WEB;
        $device = $channel === McpChatToolbox::CHANNEL_WEB ? (string) ($data['device'] ?? '') : null;
        $interactionId = (int) ($data['interaction_id'] ?? 0);

        $task = $this->em->getRepository(Task::class)->find((int) ($data['task_id'] ?? 0));
        if (!$task instanceof Task) {
            throw new \RuntimeException('task not found for McpChat job');
        }
        $company = $this->resolveCompany(isset($data['company_id']) ? (int) $data['company_id'] : null, skipUserScope: true);
        $userInteraction = $this->em->getRepository(TaskInteration::class)->find($interactionId);
        $userInteraction = $userInteraction instanceof TaskInteration ? $userInteraction : null;

        // Handed off to a human between enqueue and processing: the AI stays quiet.
        if ($task->getType() !== 'ia') {
            $this->setStatus($userInteraction, 'done');

            return;
        }

        $this->setStatus($userInteraction, 'processing');
        $this->notify($company, $task, $device, ['status' => 'processing', 'user_interaction_id' => $interactionId]);

        try {
            $resolved = $this->providers->resolve($company, isset($data['provider']) ? (string) $data['provider'] : null);
            if ($resolved === null) {
                throw new \RuntimeException('AI provider not configured for this company');
            }
            [$provider, $config] = $resolved;

            $answer = $this->asUser($channel === McpChatToolbox::CHANNEL_WEB ? $asUser : null, fn () => $this->llm->run(
                $provider,
                $config,
                $this->systemPrompt($channel, $company, $task, (string) ($config['system_prompt'] ?? '')),
                $this->loadHistory($task, $company, $channel),
                $this->toolbox->definitions($channel),
                $this->toolbox->executor($channel, $task, $company),
            ));

            // Public on WhatsApp: TaskInterationService::notifyClient delivers it to the customer.
            $visibility = $channel === McpChatToolbox::CHANNEL_WHATSAPP ? 'public' : 'private';
            $assistant = $this->addInteraction($task, $company, $answer, $visibility, 'done');
            $this->setStatus($userInteraction, 'done', flush: false);
            // Task::setAlterDate ships with tasks #18; older tasks releases lack it (it used to crash here).
            if (method_exists($task, 'setAlterDate')) {
                $task->setAlterDate(new \DateTime('now'));
            }
            $this->em->flush();

            $this->notify($company, $task, $device, [
                'status' => 'done',
                'interaction_id' => $assistant->getId(),
                'user_interaction_id' => $interactionId,
                'handoff' => $task->getType() !== 'ia' ? $task->getType() : null,
            ]);
        } catch (\Throwable $e) {
            $this->setStatus($userInteraction, 'error');
            $this->notify($company, $task, $device, [
                'status' => 'error',
                'user_interaction_id' => $interactionId,
                'body' => mb_substr($e->getMessage(), 0, 300),
            ]);
            // Not rethrown: a retry would call the LLM again and duplicate replies.
        }
    }

    /**
     * An attendant takes over a customer (WhatsApp) conversation from /ai; the AI stops answering.
     *
     * @return array{task_id: int, type: string}
     */
    public function humanHandoff(int $taskId, string $type, ?int $companyId = null): array
    {
        $company = $this->resolveCompany($companyId);
        $task = $this->em->getRepository(Task::class)->find($taskId);
        if (!$task instanceof Task || (int) $task->getProvider()?->getId() !== (int) $company->getId()) {
            throw new \InvalidArgumentException('task_id not in company scope');
        }
        // Only customer conversations; internal chats are private to their author.
        if (!in_array('via:ia', (array) $task->getAnnounce(true), true)) {
            throw new \InvalidArgumentException('task is not a customer conversation');
        }
        $auth = $this->security->getUser();
        $by = $auth instanceof User ? $auth->getPeople() : ($auth instanceof People ? $auth : null);

        $this->toolbox->handoff($task, $company, [
            'type' => $type,
            'summary' => 'Assumido manualmente por ' . trim((string) $by?->getName()),
        ]);
        $this->notify($company, $task, null, ['status' => 'done', 'handoff' => $task->getType()]);

        return ['task_id' => (int) $task->getId(), 'type' => (string) $task->getType()];
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

    /**
     * @param array<string, array<string, mixed>> $providers
     * @return array{config_id: int}
     */
    public function saveProvidersConfig(array $providers, ?int $companyId = null): array
    {
        return $this->providers->save($this->resolveCompany($companyId), $providers);
    }

    private function systemPrompt(string $channel, People $company, Task $task, string $custom): string
    {
        $companyLabel = trim((string) ($company->getAlias() ?: $company->getName())) . ' (company_id ' . $company->getId() . ')';
        $base = $channel === McpChatToolbox::CHANNEL_WHATSAPP
            ? "You are the WhatsApp virtual attendant of {$companyLabel}, talking with an external customer"
                . ($task->getClient() ? ' named ' . trim((string) $task->getClient()->getName()) : '') . ".\n"
                . "Find out what they need and collect what a human will need: their name, their company, "
                . "and the details of the request. You have no access to internal data: never invent prices, "
                . "deadlines, stock or policies. When the matter needs a person, call handoff_to_human with "
                . "type relationship for sales or support for help with the product, and then tell the customer "
                . "a person will continue here. Write short WhatsApp messages in plain text, no markdown, in the "
                . "customer's language."
            : "You are the ControleOnline assistant for {$companyLabel}. You talk with an employee of the company.\n"
                . "Answer with real data: use the tools (start with list_query_datasets / query_business_data, "
                . "scoped to this company) before stating any number, and say plainly when the data is not "
                . "available. Never invent figures. Reply in the user's language, concisely.";

        return trim($custom) !== '' ? $base . "\n\nCompany instructions:\n" . trim($custom) : $base;
    }

    /**
     * Role comes from the author: the company (AI) is "assistant"; the person who wrote is "user".
     * On WhatsApp, private notes (handoff summaries) never reach the model.
     *
     * @return list<array{role: string, content: string}>
     */
    private function loadHistory(Task $task, People $company, string $channel): array
    {
        $rows = $this->em->getRepository(TaskInteration::class)->findBy(
            ['task' => $task, 'type' => 'comment'],
            ['id' => 'DESC'],
            self::HISTORY_LIMIT,
        );
        $history = [];
        foreach (array_reverse($rows) as $row) {
            $body = trim((string) $row->getBody());
            if ($body === '' || ($channel === McpChatToolbox::CHANNEL_WHATSAPP && $row->getVisibility() !== 'public')) {
                continue;
            }
            $isAi = (int) $row->getRegisteredBy()?->getId() === (int) $company->getId();
            $history[] = ['role' => $isAi ? 'assistant' : 'user', 'content' => $body];
        }
        // Providers expect the conversation to start with the user.
        while ($history !== [] && $history[0]['role'] === 'assistant') {
            array_shift($history);
        }

        return $history;
    }

    /**
     * Run as the user who asked so MCP tools apply that user's company scope.
     */
    private function asUser(?User $user, callable $fn): mixed
    {
        if ($user === null || $this->tokenStorage === null) {
            return $fn();
        }
        $previous = $this->tokenStorage->getToken();
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'mcp_chat', $user->getRoles()));
        try {
            return $fn();
        } finally {
            $this->tokenStorage->setToken($previous);
        }
    }

    /** @param array<string, mixed> $event */
    private function notify(People $company, Task $task, ?string $device, array $event): void
    {
        try {
            $this->realtime?->notify($company, $task, $event + [
                'event' => 'mcp_chat_reply',
                'channel' => $device === null ? McpChatToolbox::CHANNEL_WHATSAPP : McpChatToolbox::CHANNEL_WEB,
            ], $device);
        } catch (\Throwable) {
            // Realtime is best effort; the front reloads on its own fallback timer.
        }
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

    private function resolveCompany(?int $companyId, bool $skipUserScope = false): People
    {
        if ($companyId !== null && $companyId > 0) {
            $company = $this->em->getRepository(People::class)->find($companyId);
            if (!$company instanceof People) {
                throw new \InvalidArgumentException('company_id not found');
            }
            if (!$skipUserScope && $this->companyScopeProvider !== null) {
                $allowed = array_column($this->companyScopeProvider->listForCurrentUser(), 'id');
                if (!in_array($companyId, $allowed, true)) {
                    throw new \InvalidArgumentException('company_id not in user scope');
                }
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

    private function resolveOrCreateTask(?int $taskId, People $user, People $company, string $provider, string $model, string $firstMessage): Task
    {
        if ($taskId !== null && $taskId > 0) {
            $task = $this->em->getRepository(Task::class)->find($taskId);
            if (!$task instanceof Task) {
                throw new \InvalidArgumentException('task_id not found');
            }
            if ($task->getType() !== 'ia') {
                throw new \InvalidArgumentException('task is not type=ia');
            }
            if ((int) $task->getProvider()?->getId() !== (int) $company->getId()) {
                throw new \InvalidArgumentException('task_id not in company scope');
            }
            // Private internal chats: only the creator may continue them.
            if ((int) $task->getRegisteredBy()?->getId() !== (int) $user->getId()) {
                throw new \InvalidArgumentException('task_id not owned by current user');
            }

            return $task;
        }

        $status = $this->em->getRepository(Status::class)->findOneBy(['context' => 'ia', 'status' => 'open']);
        if (!$status instanceof Status) {
            throw new \RuntimeException('Status context=ia open not seeded — run tasks migration Version20261101170000');
        }
        $task = new Task();
        $task->setType('ia');
        $task->setName(mb_substr($firstMessage, 0, 50));
        $task->setRegisteredBy($user);
        $task->setProvider($company);
        $task->setClient($user);
        $task->setTaskStatus($status);
        $task->setAnnounce(['channel' => 'mcp', 'ai_provider' => $provider, 'model' => $model]);
        $this->em->persist($task);
        $this->em->flush();

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
