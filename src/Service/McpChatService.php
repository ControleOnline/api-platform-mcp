<?php

declare(strict_types=1);

namespace ControleOnline\Service;

use ControleOnline\Entity\People;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\Task;
use ControleOnline\Entity\TaskInteration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Orchestrates MCP chat sessions persisted as Task(type=ia) + TaskInteration.
 * LLM provider keys stay server-side; tool calls go through McpServerService.
 */
class McpChatService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly McpServerService $mcpServer,
        private readonly ?McpCompanyScopeProviderInterface $companyScopeProvider = null,
        #[Autowire('%env(default::json:MCP_AI_PROVIDERS)%')]
        private readonly mixed $providersConfig = null,
    ) {
    }

    /**
     * @param array{provider?: string, task_id?: int, message: string, company_id?: int, model?: string} $input
     * @return array<string, mixed>
     */
    public function chat(array $input): array
    {
        $message = trim((string) ($input['message'] ?? ''));
        if ($message === '') {
            throw new \InvalidArgumentException('message is required');
        }

        $user = $this->security->getUser();
        if (!$user instanceof People) {
            throw new \RuntimeException('Authenticated People user required');
        }

        $aiProvider = strtolower((string) ($input['provider'] ?? 'grok'));
        $providerConfig = $this->resolveProviderConfig($aiProvider);
        if ($providerConfig === null) {
            throw new \InvalidArgumentException("AI provider not configured or disabled: {$aiProvider}");
        }

        $company = $this->resolveCompany($input['company_id'] ?? null);
        $task = $this->resolveOrCreateTask(
            isset($input['task_id']) ? (int) $input['task_id'] : null,
            $user,
            $company,
            $aiProvider,
            $providerConfig['model'] ?? ($input['model'] ?? null),
            $message,
        );

        $this->addInteraction($task, $user, 'comment', $message, 'private');

        $history = $this->loadHistory($task);
        $assistantText = $this->runLlmWithMcpTools($providerConfig, $history, $message);

        // System/AI author: reuse company people as registeredBy when no dedicated bot people exists
        $this->addInteraction($task, $company, 'comment', $assistantText, 'private');

        $task->setAlterDate(new \DateTime('now'));
        $this->em->flush();

        return [
            'task_id' => $task->getId(),
            'type' => 'ia',
            'provider' => $aiProvider,
            'message' => $assistantText,
            'history_count' => count($history) + 2,
        ];
    }

    /**
     * @return list<array{provider: string, label: string, model: string, enabled: bool}>
     */
    public function listConfiguredProviders(): array
    {
        $out = [];
        foreach ($this->normalizedProviders() as $key => $cfg) {
            if (!($cfg['enabled'] ?? false)) {
                continue;
            }
            if (empty($cfg['api_key'])) {
                continue;
            }
            $out[] = [
                'provider' => $key,
                'label' => (string) ($cfg['label'] ?? $key),
                'model' => (string) ($cfg['model'] ?? ''),
                'enabled' => true,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveProviderConfig(string $key): ?array
    {
        $all = $this->normalizedProviders();
        $cfg = $all[$key] ?? null;
        if (!is_array($cfg) || !($cfg['enabled'] ?? false) || empty($cfg['api_key'])) {
            return null;
        }

        return $cfg;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function normalizedProviders(): array
    {
        $raw = $this->providersConfig;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $defaults = [
            'grok' => ['label' => 'Grok', 'base_url' => 'https://api.x.ai/v1', 'model' => 'grok-4'],
            'openai' => ['label' => 'Codex / OpenAI', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o'],
            'gemini' => ['label' => 'Gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-2.0-flash'],
            'claude' => ['label' => 'Claude', 'base_url' => 'https://api.anthropic.com/v1', 'model' => 'claude-sonnet-4'],
        ];

        $out = [];
        foreach ($defaults as $key => $def) {
            $cfg = is_array($raw[$key] ?? null) ? $raw[$key] : [];
            $out[$key] = array_merge($def, $cfg, ['enabled' => (bool) ($cfg['enabled'] ?? false)]);
        }

        return $out;
    }

    private function resolveCompany(?int $companyId): People
    {
        if ($companyId !== null && $companyId > 0) {
            $company = $this->em->getRepository(People::class)->find($companyId);
            if (!$company instanceof People) {
                throw new \InvalidArgumentException('company_id not found');
            }
            if ($this->companyScopeProvider !== null) {
                $allowed = array_column($this->companyScopeProvider->listForCurrentUser(), 'id');
                if (!in_array($companyId, $allowed, true)) {
                    throw new \InvalidArgumentException('company_id not in user scope');
                }
            }

            return $company;
        }

        if ($this->companyScopeProvider !== null) {
            $list = $this->companyScopeProvider->listForCurrentUser();
            if ($list === []) {
                throw new \RuntimeException('No accessible company for current user');
            }
            $first = $this->em->getRepository(People::class)->find((int) $list[0]['id']);
            if ($first instanceof People) {
                return $first;
            }
        }

        throw new \RuntimeException('Unable to resolve company');
    }

    private function resolveOrCreateTask(
        ?int $taskId,
        People $user,
        People $company,
        string $aiProvider,
        ?string $model,
        string $firstMessage,
    ): Task {
        if ($taskId !== null && $taskId > 0) {
            $task = $this->em->getRepository(Task::class)->find($taskId);
            if (!$task instanceof Task) {
                throw new \InvalidArgumentException('task_id not found');
            }
            if ($task->getType() !== 'ia') {
                throw new \InvalidArgumentException('task is not type=ia');
            }

            return $task;
        }

        $status = $this->em->getRepository(Status::class)->findOneBy([
            'context' => 'ia',
            'status' => 'open',
        ]);
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
        $task->setAnnounce([
            'channel' => 'mcp',
            'ai_provider' => $aiProvider,
            'model' => $model,
        ]);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function addInteraction(Task $task, People $by, string $type, string $body, string $visibility): TaskInteration
    {
        $row = new TaskInteration();
        $row->setTask($task);
        $row->setRegisteredBy($by);
        $row->setType($type);
        $row->setBody($body);
        $row->setVisibility($visibility);
        $row->setRead(0);
        $row->setNotified(0);
        $this->em->persist($row);

        return $row;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function loadHistory(Task $task): array
    {
        /** @var list<TaskInteration> $rows */
        $rows = $this->em->getRepository(TaskInteration::class)->findBy(
            ['task' => $task],
            ['id' => 'ASC'],
            40
        );
        $history = [];
        foreach ($rows as $row) {
            if ($row->getType() !== 'comment') {
                continue;
            }
            $history[] = [
                'role' => 'user',
                'content' => (string) $row->getBody(),
            ];
        }

        return $history;
    }

    /**
     * @param array<string, mixed> $providerConfig
     * @param list<array{role: string, content: string}> $history
     */
    private function runLlmWithMcpTools(array $providerConfig, array $history, string $userMessage): string
    {
        // Gather MCP context for the model (companies + optional aggregate hint)
        $mcpContext = '';
        try {
            $companies = $this->mcpServer->handle([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'list_my_companies', 'arguments' => []],
            ]);
            $mcpContext = json_encode($companies['result'] ?? $companies, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable) {
            $mcpContext = '{}';
        }

        $system = "You are the ControleOnline company assistant. Answer using MCP business data only. "
            . "Companies in scope (JSON): {$mcpContext}. "
            . "If you need numbers, say what query would be required. Reply in the user language.";

        $messages = array_merge(
            [['role' => 'system', 'content' => $system]],
            array_slice($history, -12),
            [['role' => 'user', 'content' => $userMessage]],
        );

        $baseUrl = rtrim((string) ($providerConfig['base_url'] ?? 'https://api.x.ai/v1'), '/');
        $model = (string) ($providerConfig['model'] ?? 'grok-4');
        $apiKey = (string) $providerConfig['api_key'];

        // OpenAI-compatible chat completions (Grok/OpenAI); Claude/Gemini adapters can extend later
        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.2,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new \RuntimeException('AI provider request failed');
        }
        $decoded = json_decode($raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            throw new \RuntimeException('AI provider error HTTP ' . $status);
        }

        $text = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('AI provider returned empty content');
        }

        return $text;
    }
}
