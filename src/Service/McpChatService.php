<?php

declare(strict_types=1);

namespace ControleOnline\Service;

use ControleOnline\Entity\Config;
use ControleOnline\Entity\Module;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\Task;
use ControleOnline\Entity\TaskInteration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
/**
 * Orchestrates MCP chat sessions persisted as Task(type=ia) + TaskInteration.
 * LLM provider keys stay server-side; tool calls go through McpServerService.
 */
class McpChatService
{
    public const CONFIG_KEY = 'ai-providers';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly McpServerService $mcpServer,
        private readonly ?McpCompanyScopeProviderInterface $companyScopeProvider = null,
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

        $company = $this->resolveCompany($input['company_id'] ?? null);
        $aiProvider = strtolower((string) ($input['provider'] ?? 'grok'));
        $providerConfig = $this->resolveProviderConfig($company, $aiProvider);
        if ($providerConfig === null) {
            throw new \InvalidArgumentException("AI provider not configured or disabled for this company: {$aiProvider}");
        }
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
        $assistantText = $this->runLlmWithMcpTools($aiProvider, $providerConfig, $history, $message);

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
    public function listConfiguredProviders(?int $companyId = null): array
    {
        $company = $this->resolveCompany($companyId);
        $out = [];
        foreach ($this->loadProvidersFromConfig($company) as $key => $cfg) {
            if (!($cfg['enabled'] ?? false) || empty($cfg['api_key'])) {
                continue;
            }
            $out[] = [
                'provider' => $key,
                'label' => (string) ($cfg['label'] ?? $key),
                'model' => (string) ($cfg['model'] ?? ''),
                'enabled' => true,
                'has_key' => true,
                'api_key_masked' => $this->maskKey((string) $cfg['api_key']),
            ];
        }

        return $out;
    }

    /** @return array{company_id: int, config_id: int|null, config_key: string, providers: array<string, mixed>} */
    public function getProvidersConfigForUi(?int $companyId = null): array
    {
        $company = $this->resolveCompany($companyId);
        $row = $this->findConfigRow($company);
        $masked = [];
        foreach ($this->loadProvidersFromConfig($company) as $key => $cfg) {
            $keyVal = (string) ($cfg['api_key'] ?? '');
            $masked[$key] = [
                'label' => $cfg['label'] ?? $key,
                'enabled' => (bool) ($cfg['enabled'] ?? false),
                'model' => (string) ($cfg['model'] ?? ''),
                'base_url' => (string) ($cfg['base_url'] ?? ''),
                'system_prompt' => (string) ($cfg['system_prompt'] ?? ''),
                'has_key' => $keyVal !== '',
                'api_key_masked' => $keyVal !== '' ? $this->maskKey($keyVal) : null,
            ];
        }

        return [
            'company_id' => $company->getId(),
            'config_id' => $row?->getId(),
            'config_key' => self::CONFIG_KEY,
            'providers' => $masked,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $providers
     * @return array{config_id: int}
     */
    public function saveProvidersConfig(array $providers, ?int $companyId = null): array
    {
        $company = $this->resolveCompany($companyId);
        $existing = $this->loadProvidersFromConfig($company);
        $merged = [];
        foreach ($this->defaultProviders() as $key => $def) {
            $incoming = is_array($providers[$key] ?? null) ? $providers[$key] : [];
            $prev = $existing[$key] ?? [];
            $apiKey = trim((string) ($incoming['api_key'] ?? ''));
            if ($apiKey === '' || str_contains($apiKey, '*')) {
                $apiKey = (string) ($prev['api_key'] ?? '');
            }
            $merged[$key] = [
                'label' => (string) ($incoming['label'] ?? $def['label']),
                'enabled' => (bool) ($incoming['enabled'] ?? false),
                'model' => (string) ($incoming['model'] ?? $def['model']),
                'base_url' => (string) ($incoming['base_url'] ?? $def['base_url']),
                'api_key' => $apiKey,
                'system_prompt' => (string) ($incoming['system_prompt'] ?? $prev['system_prompt'] ?? ''),
            ];
        }
        $row = $this->findConfigRow($company);
        if ($row === null) {
            $row = new Config();
            $row->setPeople($company);
            $row->setConfigKey(self::CONFIG_KEY);
            $row->setVisibility('private');
            $row->setModule($this->resolveConfigModule());
            $this->em->persist($row);
        } elseif ($row->getModule() === null) {
            $row->setModule($this->resolveConfigModule());
        }
        $row->setConfigValue(json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->em->flush();

        return ['config_id' => $row->getId()];
    }

    /**
     * @return array<string, mixed>|null
     */
    
    private function resolveProviderConfig(People $company, string $key): ?array
    {
        $all = $this->loadProvidersFromConfig($company);
        $cfg = $all[$key] ?? null;
        if (!is_array($cfg) || !($cfg['enabled'] ?? false) || empty($cfg['api_key'])) {
            return null;
        }

        return $cfg;
    }

    /** @return array<string, array<string, mixed>> */
    private function defaultProviders(): array
    {
        return [
            'grok' => ['label' => 'Grok', 'base_url' => 'https://api.x.ai/v1', 'model' => 'grok-4'],
            'openai' => ['label' => 'Codex / OpenAI', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o'],
            'gemini' => ['label' => 'Gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-2.0-flash'],
            'claude' => ['label' => 'Claude', 'base_url' => 'https://api.anthropic.com/v1', 'model' => 'claude-sonnet-4'],
        ];
    }

    private function findConfigRow(People $company): ?Config
    {
        $row = $this->em->getRepository(Config::class)->findOneBy([
            'people' => $company,
            'configKey' => self::CONFIG_KEY,
        ]);

        return $row instanceof Config ? $row : null;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadProvidersFromConfig(People $company): array
    {
        $defaults = $this->defaultProviders();
        $row = $this->findConfigRow($company);
        $raw = [];
        if ($row !== null) {
            $decoded = json_decode($row->getConfigValue(), true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }
        $out = [];
        foreach ($defaults as $key => $def) {
            $cfg = is_array($raw[$key] ?? null) ? $raw[$key] : [];
            $out[$key] = array_merge($def, $cfg);
        }

        return $out;
    }

    private function maskKey(string $key): string
    {
        $len = strlen($key);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        return substr($key, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($key, -4);
    }


    private function resolveConfigModule(): Module
    {
        $repository = $this->em->getRepository(Module::class);
        foreach (['mcp', 'config', 'common', 'ui-manager', 'financial'] as $moduleName) {
            $module = $repository->findOneBy(['name' => $moduleName]);
            if ($module instanceof Module) {
                return $module;
            }
        }
        $module = $repository->findOneBy([], ['id' => 'ASC']);
        if ($module instanceof Module) {
            return $module;
        }
        throw new \RuntimeException('No module available to attach ai-providers config.');
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
            $taskProvider = $task->getProvider();
            if (!$taskProvider instanceof People || (int) $taskProvider->getId() !== (int) $company->getId()) {
                throw new \InvalidArgumentException('task_id not in company scope');
            }
            // Private IA chats: only the creator (or company people acting as bot) may continue.
            $owner = $task->getRegisteredBy();
            if ($owner instanceof People && (int) $owner->getId() !== (int) $user->getId()) {
                throw new \InvalidArgumentException('task_id not owned by current user');
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
    private function runLlmWithMcpTools(string $providerKey, array $providerConfig, array $history, string $userMessage): string
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

        $custom = trim((string) ($providerConfig['system_prompt'] ?? ''));
        $system = ($custom !== '' ? $custom . "\n\n" : "")
            . "You are the ControleOnline company assistant. Answer using MCP business data only. "
            . "Companies in scope (JSON): {$mcpContext}. "
            . "If you need numbers, say what query would be required. Reply in the user language.";

        $historySlice = array_slice($history, -12);
        $baseUrl = rtrim((string) ($providerConfig['base_url'] ?? 'https://api.x.ai/v1'), '/');
        $model = (string) ($providerConfig['model'] ?? 'grok-4');
        $apiKey = (string) $providerConfig['api_key'];
        $providerKey = strtolower($providerKey);

        if ($providerKey === 'claude' || str_contains($baseUrl, 'anthropic.com')) {
            return $this->callClaude($baseUrl, $model, $apiKey, $system, $historySlice, $userMessage);
        }
        if ($providerKey === 'gemini' || str_contains($baseUrl, 'generativelanguage.googleapis.com')) {
            return $this->callGemini($baseUrl, $model, $apiKey, $system, $historySlice, $userMessage);
        }

        return $this->callOpenAiCompatible($baseUrl, $model, $apiKey, $system, $historySlice, $userMessage);
    }

    /**
     * @param list<array{role: string, content: string}> $historySlice
     */
    private function callOpenAiCompatible(
        string $baseUrl,
        string $model,
        string $apiKey,
        string $system,
        array $historySlice,
        string $userMessage,
    ): string {
        $messages = array_merge(
            [['role' => 'system', 'content' => $system]],
            $historySlice,
            [['role' => 'user', 'content' => $userMessage]],
        );
        $decoded = $this->httpJson(
            $baseUrl . '/chat/completions',
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            [
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.2,
            ],
        );
        $text = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('AI provider returned empty content');
        }

        return $text;
    }

    /**
     * @param list<array{role: string, content: string}> $historySlice
     */
    private function callClaude(
        string $baseUrl,
        string $model,
        string $apiKey,
        string $system,
        array $historySlice,
        string $userMessage,
    ): string {
        $messages = [];
        foreach ($historySlice as $row) {
            $role = (string) ($row['role'] ?? '');
            if ($role === 'system' || $role === '') {
                continue;
            }
            if ($role !== 'user' && $role !== 'assistant') {
                $role = 'user';
            }
            $messages[] = ['role' => $role, 'content' => (string) ($row['content'] ?? '')];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];
        $decoded = $this->httpJson(
            $baseUrl . '/messages',
            [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            [
                'model' => $model,
                'max_tokens' => 2048,
                'system' => $system,
                'messages' => $messages,
                'temperature' => 0.2,
            ],
        );
        $blocks = $decoded['content'] ?? [];
        $text = '';
        if (is_array($blocks)) {
            foreach ($blocks as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text') {
                    $text .= (string) ($block['text'] ?? '');
                }
            }
        }
        if ($text === '') {
            throw new \RuntimeException('Claude returned empty content');
        }

        return $text;
    }

    /**
     * @param list<array{role: string, content: string}> $historySlice
     */
    private function callGemini(
        string $baseUrl,
        string $model,
        string $apiKey,
        string $system,
        array $historySlice,
        string $userMessage,
    ): string {
        $contents = [];
        foreach ($historySlice as $row) {
            $role = (string) ($row['role'] ?? 'user');
            $geminiRole = $role === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $geminiRole,
                'parts' => [['text' => (string) ($row['content'] ?? '')]],
            ];
        }
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $userMessage]],
        ];
        $url = rtrim($baseUrl, '/') . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        $decoded = $this->httpJson(
            $url,
            ['Content-Type: application/json'],
            [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => $contents,
                'generationConfig' => ['temperature' => 0.2],
            ],
        );
        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('Gemini returned empty content');
        }

        return $text;
    }

    /**
     * @param list<string> $headers
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function httpJson(string $url, array $headers, array $body): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('Failed to encode AI request');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
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
            $snippet = is_string($raw) ? mb_substr($raw, 0, 200) : '';
            throw new \RuntimeException('AI provider error HTTP ' . $status . ($snippet !== '' ? ': ' . $snippet : ''));
        }

        return $decoded;
    }
}
