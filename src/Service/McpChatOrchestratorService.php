<?php

declare(strict_types=1);

namespace ControleOnline\Service;

use ControleOnline\Entity\People;
use ControleOnline\Entity\Task;
use ControleOnline\Entity\TaskInteration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Orchestrates /mcp/chat: persists conversation on Task type=ia + TaskInteration,
 * loads company AI provider config, and calls an OpenAI-compatible chat endpoint.
 */
class McpChatOrchestratorService
{
    private const PROVIDERS = ['grok', 'openai', 'codex', 'gemini', 'claude'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ConfigService $configService,
        private readonly ?StatusService $statusService = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array{message?: string, task_id?: int|null, provider?: string|null, company_id?: int|null} $input
     * @return array<string, mixed>
     */
    public function chat(array $input): array
    {
        $message = trim((string) ($input['message'] ?? ''));
        if ($message === '') {
            throw new \InvalidArgumentException('message is required');
        }

        $company = $this->resolveCompany($input['company_id'] ?? null);
        $providerKey = $this->normalizeProvider($input['provider'] ?? null, $company);
        $providerCfg = $this->loadProviderConfig($company, $providerKey);
        if (!($providerCfg['enabled'] ?? false)) {
            throw new \RuntimeException(sprintf('Provider "%s" is disabled or not configured', $providerKey));
        }
        if (empty($providerCfg['api_key'])) {
            throw new \RuntimeException(sprintf('Provider "%s" has no api_key configured', $providerKey));
        }

        $task = $this->resolveTask($company, $input['task_id'] ?? null, $providerKey, $providerCfg, $message);
        $userPeople = $this->currentPeople() ?? $company;

        $userIx = $this->persistInteration($task, $userPeople, 'comment', $message);
        $history = $this->loadHistory($task);
        $systemPrompt = (string) ($providerCfg['system_prompt'] ?? $providerCfg['prompt'] ?? 'You are a helpful assistant with access to ControleOnline business data via MCP tools.');

        $assistantText = $this->callLlm($providerKey, $providerCfg, $systemPrompt, $history);

        $assistantIx = $this->persistInteration($task, $company, 'comment', $assistantText);

        return [
            'task' => [
                'id' => $task->getId(),
                'name' => $task->getName(),
                'type' => $task->getType(),
                'announce' => $task->getAnnounce(true),
            ],
            'provider' => $providerKey,
            'user_interation_id' => $userIx->getId(),
            'assistant_interation_id' => $assistantIx->getId(),
            'reply' => $assistantText,
        ];
    }

    /**
     * @return list<array{id:int,name:string,enabled:bool,model:?string,has_key:bool,system_prompt:?string}>
     */
    public function listProviders(People $company): array
    {
        $cfg = $this->loadAllConfig($company);
        $out = [];
        foreach (self::PROVIDERS as $key) {
            $p = $cfg['providers'][$key] ?? [];
            $out[] = [
                'id' => $key === 'codex' ? 'openai' : $key,
                'name' => $key,
                'enabled' => (bool) ($p['enabled'] ?? false),
                'model' => $p['model'] ?? null,
                'has_key' => !empty($p['api_key']),
                'system_prompt' => $p['system_prompt'] ?? $p['prompt'] ?? null,
            ];
        }
        return $out;
    }

    public function resolveCompany(?int $companyId): People
    {
        if ($companyId) {
            $company = $this->em->getRepository(People::class)->find($companyId);
            if (!$company) {
                throw new \InvalidArgumentException('company not found');
            }
            return $company;
        }
        // Prefer people from token context when available via PeopleService patterns — fall back first company link later
        $people = $this->currentPeople();
        if ($people) {
            return $people;
        }
        throw new \RuntimeException('Unable to resolve company for chat');
    }

    private function currentPeople(): ?People
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if ($user && method_exists($user, 'getPeople')) {
            return $user->getPeople();
        }
        return null;
    }

    private function normalizeProvider(?string $requested, People $company): string
    {
        $cfg = $this->loadAllConfig($company);
        $key = strtolower((string) $requested);
        if ($key === 'codex') {
            $key = 'openai';
        }
        if ($key !== '' && isset($cfg['providers'][$key])) {
            return $key;
        }
        $default = strtolower((string) ($cfg['default_provider'] ?? 'grok'));
        if ($default === 'codex') {
            $default = 'openai';
        }
        return $default !== '' ? $default : 'grok';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadAllConfig(People $company): array
    {
        $raw = $this->configService->getConfig($company, 'mcp-ai-config', true);
        if (!is_array($raw)) {
            $raw = [];
        }
        if (!isset($raw['providers']) || !is_array($raw['providers'])) {
            $raw['providers'] = [];
        }
        // Legacy flat keys mcp-ai-*
        foreach (['grok', 'openai', 'gemini', 'claude'] as $k) {
            $legacy = $this->configService->getConfig($company, 'mcp-ai-' . $k, true);
            if (is_array($legacy) && !isset($raw['providers'][$k])) {
                $raw['providers'][$k] = $legacy;
            }
        }
        $enabled = $this->configService->getConfig($company, 'mcp-ai-enabled', false);
        if ($enabled && empty($raw['providers'])) {
            $provider = (string) $this->configService->getConfig($company, 'mcp-ai-provider', false);
            $raw['providers'][$provider ?: 'grok'] = [
                'enabled' => true,
                'api_key' => $this->configService->getConfig($company, 'mcp-ai-api-key', false),
                'model' => $this->configService->getConfig($company, 'mcp-ai-model', false),
                'base_url' => $this->configService->getConfig($company, 'mcp-ai-base-url', false),
                'system_prompt' => $this->configService->getConfig($company, 'mcp-ai-system-prompt', false),
            ];
        }
        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadProviderConfig(People $company, string $providerKey): array
    {
        $all = $this->loadAllConfig($company);
        $p = $all['providers'][$providerKey] ?? [];
        return is_array($p) ? $p : [];
    }

    private function resolveTask(People $company, mixed $taskId, string $providerKey, array $providerCfg, string $firstMessage): Task
    {
        if ($taskId) {
            $task = $this->em->getRepository(Task::class)->find((int) $taskId);
            if (!$task || $task->getType() !== 'ia') {
                throw new \InvalidArgumentException('task not found or not type=ia');
            }
            if ($task->getProvider()?->getId() !== $company->getId()) {
                throw new \RuntimeException('task does not belong to company');
            }
            return $task;
        }
        $name = mb_substr($firstMessage, 0, 40);
        $userPeople = $this->currentPeople() ?? $company;
        $task = new Task();
        $task->setName($name !== '' ? $name : 'Chat IA');
        $task->setRegisteredBy($userPeople);
        $task->setTaskFor($userPeople);
        $task->setProvider($company);
        $task->setClient($company);
        $task->setType('ia');
        if ($this->statusService) {
            $task->setTaskStatus($this->statusService->discoveryStatus('open', 'open', 'ia'));
        } else {
            $status = $this->em->getRepository(\ControleOnline\Entity\Status::class)->findOneBy(['status' => 'open', 'context' => 'ia']);
            if (!$status) {
                throw new \RuntimeException('Status open/ia not seeded — run tasks migration');
            }
            $task->setTaskStatus($status);
        }
        $task->setAnnounce([
            'channel' => 'mcp',
            'ai_provider' => $providerKey,
            'model' => $providerCfg['model'] ?? null,
        ]);
        $this->em->persist($task);
        $this->em->flush();
        return $task;
    }

    private function persistInteration(Task $task, People $by, string $type, string $body): TaskInteration
    {
        $ix = new TaskInteration();
        $ix->setTask($task);
        $ix->setType($type);
        $ix->setVisibility('private');
        $ix->setBody($body);
        $ix->setRegisteredBy($by);
        $this->em->persist($ix);
        $this->em->flush();
        return $ix;
    }

    /**
     * @return list<array{role:string,content:string}>
     */
    private function loadHistory(Task $task): array
    {
        $rows = $this->em->getRepository(TaskInteration::class)->findBy(
            ['task' => $task, 'type' => 'comment'],
            ['id' => 'ASC'],
            40
        );
        $msgs = [];
        foreach ($rows as $row) {
            $isUser = $row->getRegisteredBy()?->getId() !== $task->getProvider()?->getId();
            $msgs[] = [
                'role' => $isUser ? 'user' : 'assistant',
                'content' => (string) $row->getBody(),
            ];
        }
        return $msgs;
    }

    /**
     * @param array<string, mixed> $providerCfg
     * @param list<array{role:string,content:string}> $history
     */
    private function callLlm(string $providerKey, array $providerCfg, string $systemPrompt, array $history): string
    {
        $apiKey = (string) $providerCfg['api_key'];
        $model = (string) ($providerCfg['model'] ?? $this->defaultModel($providerKey));
        $baseUrl = rtrim((string) ($providerCfg['base_url'] ?? $this->defaultBaseUrl($providerKey)), '/');

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history
        );

        // OpenAI-compatible path (Grok, OpenAI/Codex, many Gemini proxies)
        if (in_array($providerKey, ['grok', 'openai', 'gemini'], true)) {
            return $this->callOpenAiCompatible($baseUrl, $apiKey, $model, $messages);
        }
        if ($providerKey === 'claude') {
            return $this->callClaude($baseUrl, $apiKey, $model, $systemPrompt, $history);
        }
        return $this->callOpenAiCompatible($baseUrl, $apiKey, $model, $messages);
    }

    private function defaultModel(string $providerKey): string
    {
        return match ($providerKey) {
            'grok' => 'grok-4',
            'openai' => 'gpt-4o-mini',
            'gemini' => 'gemini-2.0-flash',
            'claude' => 'claude-sonnet-4-20250514',
            default => 'gpt-4o-mini',
        };
    }

    private function defaultBaseUrl(string $providerKey): string
    {
        return match ($providerKey) {
            'grok' => 'https://api.x.ai/v1',
            'openai' => 'https://api.openai.com/v1',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'claude' => 'https://api.anthropic.com',
            default => 'https://api.openai.com/v1',
        };
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     */
    private function callOpenAiCompatible(string $baseUrl, string $apiKey, string $model, array $messages): string
    {
        $url = $baseUrl . '/chat/completions';
        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.3,
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $raw === false) {
            throw new \RuntimeException('LLM request failed: curl error ' . $errno);
        }
        $data = json_decode($raw, true);
        if ($status >= 400) {
            $err = is_array($data) ? ($data['error']['message'] ?? $raw) : $raw;
            throw new \RuntimeException('LLM HTTP ' . $status . ': ' . $err);
        }
        $text = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('LLM returned empty content');
        }
        return $text;
    }

    /**
     * @param list<array{role:string,content:string}> $history
     */
    private function callClaude(string $baseUrl, string $apiKey, string $model, string $systemPrompt, array $history): string
    {
        $url = rtrim($baseUrl, '/') . '/v1/messages';
        $payload = json_encode([
            'model' => $model,
            'max_tokens' => 2048,
            'system' => $systemPrompt,
            'messages' => array_map(static fn ($m) => [
                'role' => $m['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $m['content'],
            ], $history),
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string) $raw, true);
        if ($status >= 400) {
            throw new \RuntimeException('Claude HTTP ' . $status . ': ' . ($data['error']['message'] ?? $raw));
        }
        $text = $data['content'][0]['text'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('Claude returned empty content');
        }
        return $text;
    }
}
