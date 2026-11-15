<?php

declare(strict_types=1);

namespace ControleOnline\Service;

/**
 * Tool-use loop over the company's configured LLM (raw HTTP, keys stay server-side).
 *
 * Tools are passed in a neutral shape: ['name', 'description', 'schema'].
 * The executor runs one tool call and returns its result as text.
 * Supported wire formats: OpenAI-compatible (Grok/OpenAI), Anthropic Messages, Gemini.
 */
class McpChatLlmClient
{
    private const MAX_ROUNDS = 8;
    private const TIMEOUT_SECONDS = 60;
    private const CONNECT_TIMEOUT_SECONDS = 10;
    /** Attempts per model before moving to the next one (transient errors only). */
    private const ATTEMPTS_PER_MODEL = 2;
    /** Used when the configured Gemini model is overloaded (503) or retired; override with config fallback_models. */
    private const GEMINI_FALLBACK_MODELS = ['gemini-3.5-flash', 'gemini-flash-latest'];
    private const TRANSIENT_HTTP = [408, 425, 429, 500, 502, 503, 504, 529];
    /** A model that failed is tried last for this long, so the next messages start with one that works. */
    private const COOLDOWN_SECONDS = 600;

    /**
     * @param array<string, mixed> $config provider config (base_url, model, api_key, workspace_id)
     * @param list<array{role: string, content: string}> $history oldest first, ends with the user turn
     * @param list<array{name: string, description: string, schema: array<string, mixed>}> $tools
     * @param callable(string, array<string, mixed>): string $executor
     */
    public function run(string $provider, array $config, string $system, array $history, array $tools, callable $executor): string
    {
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $provider = strtolower($provider);

        if ($provider === 'claude' || str_contains($baseUrl, 'anthropic.com')) {
            return $this->runClaude($baseUrl, $config, $system, $history, $tools, $executor);
        }
        if ($provider === 'gemini' || str_contains($baseUrl, 'generativelanguage.googleapis.com')) {
            return $this->runGemini($baseUrl, $config, $system, $history, $tools, $executor);
        }

        return $this->runOpenAi($baseUrl, $config, $system, $history, $tools, $executor);
    }

    private function runOpenAi(string $baseUrl, array $config, string $system, array $history, array $tools, callable $executor): string
    {
        $messages = [['role' => 'system', 'content' => $system], ...$history];
        $body = ['model' => (string) $config['model']];
        if ($tools !== []) {
            $body['tools'] = array_map(static fn (array $t) => [
                'type' => 'function',
                'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['schema']],
            ], $tools);
        }
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $config['api_key']];

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $models = [(string) $config['model']];
            $decoded = $this->request($models, static fn () => [$baseUrl . '/chat/completions', $headers, $body + ['messages' => $messages]]);
            $message = $decoded['choices'][0]['message'] ?? null;
            if (!is_array($message)) {
                throw new \RuntimeException('AI provider returned no message');
            }
            $calls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
            if ($calls === []) {
                return $this->requireText((string) ($message['content'] ?? ''));
            }
            $messages[] = $message;
            foreach ($calls as $call) {
                $args = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($call['id'] ?? ''),
                    'content' => $this->execute($executor, (string) ($call['function']['name'] ?? ''), $args),
                ];
            }
        }

        throw new \RuntimeException('AI tool loop exceeded ' . self::MAX_ROUNDS . ' rounds');
    }

    private function runClaude(string $baseUrl, array $config, string $system, array $history, array $tools, callable $executor): string
    {
        $headers = ['Content-Type: application/json', 'x-api-key: ' . $config['api_key'], 'anthropic-version: 2023-06-01'];
        // The API key already selects the workspace; an anthropic-workspace-id header with a stale id
        // makes every request fail (400), so it is never sent.
        // Current Claude models reject sampling params (temperature/top_p); thinking stays at model default.
        $body = ['model' => (string) $config['model'], 'max_tokens' => 16000, 'system' => $system];
        if ($tools !== []) {
            $body['tools'] = array_map(static fn (array $t) => [
                'name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['schema'],
            ], $tools);
        }
        $messages = $this->mergeSameRole($history);

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $models = [(string) $config['model']];
            $decoded = $this->request($models, static fn () => [$baseUrl . '/messages', $headers, $body + ['messages' => $messages]]);
            $blocks = is_array($decoded['content'] ?? null) ? $decoded['content'] : [];
            $stop = (string) ($decoded['stop_reason'] ?? '');

            if ($stop === 'refusal') {
                return 'Não posso ajudar com esse pedido.';
            }
            if ($stop !== 'tool_use') {
                $text = '';
                foreach ($blocks as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $text .= (string) ($block['text'] ?? '');
                    }
                }

                return $this->requireText($text);
            }

            // Pass the assistant turn back unchanged (thinking blocks included);
            // every tool_result goes in one user message.
            $messages[] = ['role' => 'assistant', 'content' => array_map(
                static fn (array $b) => ($b['type'] ?? '') === 'tool_use' && ($b['input'] ?? []) === []
                    ? ['input' => new \stdClass()] + $b
                    : $b,
                $blocks,
            )];
            $results = [];
            foreach ($blocks as $block) {
                if (($block['type'] ?? '') !== 'tool_use') {
                    continue;
                }
                $results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => (string) $block['id'],
                    'content' => $this->execute($executor, (string) $block['name'], $block['input'] ?? []),
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        throw new \RuntimeException('AI tool loop exceeded ' . self::MAX_ROUNDS . ' rounds');
    }

    private function runGemini(string $baseUrl, array $config, string $system, array $history, array $tools, callable $executor): string
    {
        $contents = array_map(static fn (array $m) => [
            'role' => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ], $history);
        $body = ['systemInstruction' => ['parts' => [['text' => $system]]]];
        if ($tools !== []) {
            $body['tools'] = [['functionDeclarations' => array_map(function (array $t) {
                $declaration = ['name' => $t['name'], 'description' => $t['description']];
                $schema = $this->geminiSchema($t['schema']);
                if (!empty($schema['properties'])) {
                    $declaration['parameters'] = $schema;
                }

                return $declaration;
            }, $tools)]];
        }
        $models = $this->geminiModels($config);
        $urlFor = static fn (string $model) => $baseUrl . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode((string) $config['api_key']);

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            // The model that answered stays first for the rest of the tool loop.
            $decoded = $this->request($models, static fn (string $model) => [$urlFor($model), ['Content-Type: application/json'], $body + ['contents' => $contents]]);
            $content = $decoded['candidates'][0]['content'] ?? null;
            $parts = is_array($content['parts'] ?? null) ? $content['parts'] : [];
            $calls = array_values(array_filter($parts, static fn ($p) => isset($p['functionCall'])));
            if ($calls === []) {
                $text = implode('', array_map(static fn ($p) => (string) ($p['text'] ?? ''), $parts));

                return $this->requireText($text);
            }
            $contents[] = ['role' => 'model', 'parts' => array_map(
                static fn (array $p) => isset($p['functionCall']) && ($p['functionCall']['args'] ?? []) === []
                    ? ['functionCall' => ['name' => $p['functionCall']['name'], 'args' => new \stdClass()]] + $p
                    : $p,
                $parts,
            )];
            $responses = [];
            foreach ($calls as $part) {
                $name = (string) ($part['functionCall']['name'] ?? '');
                $responses[] = ['functionResponse' => [
                    'name' => $name,
                    'response' => ['result' => $this->execute($executor, $name, $part['functionCall']['args'] ?? [])],
                ]];
            }
            $contents[] = ['role' => 'user', 'parts' => $responses];
        }

        throw new \RuntimeException('AI tool loop exceeded ' . self::MAX_ROUNDS . ' rounds');
    }

    /**
     * Calls the provider, retrying transient failures (overload, rate limit, timeout) and then trying the
     * next model. $models is reordered so the model that answered is tried first on the next round.
     *
     * @param list<string> $models
     * @param callable(string): array{0: string, 1: list<string>, 2: array<string, mixed>} $build url, headers, body
     * @return array<string, mixed>
     */
    private function request(array &$models, callable $build): array
    {
        $models = $this->byCooldown($models);
        $last = null;
        foreach ($models as $i => $model) {
            for ($attempt = 1; $attempt <= self::ATTEMPTS_PER_MODEL; $attempt++) {
                try {
                    [$url, $headers, $body] = $build($model);
                    $decoded = $this->httpJson($url, $headers, $body);
                    if ($i > 0) {
                        array_splice($models, $i, 1);
                        array_unshift($models, $model);
                    }

                    return $decoded;
                } catch (McpChatProviderUnavailableException $e) {
                    $last = $e;
                    if (count($models) > 1) {
                        $this->coolDown($model);
                    }
                    if ($e->modelGone || ($e->timedOut && count($models) > 1)) {
                        break; // retired or hanging model: go to the next one right away
                    }
                    if ($attempt < self::ATTEMPTS_PER_MODEL) {
                        $this->pause($attempt);
                    }
                }
            }
        }

        throw new \RuntimeException('AI provider unavailable after retries: ' . ($last?->getMessage() ?? 'no model'), 0, $last);
    }

    /** @return list<string> */
    private function geminiModels(array $config): array
    {
        $fallback = $config['fallback_models'] ?? self::GEMINI_FALLBACK_MODELS;
        $fallback = is_string($fallback) ? (preg_split('/[\s,]+/', $fallback) ?: []) : (array) $fallback;

        return array_values(array_unique(array_filter(array_map(
            static fn ($m) => trim((string) $m),
            [(string) ($config['model'] ?? ''), ...$fallback],
        ))));
    }

    /**
     * @param list<string> $models
     * @return list<string> models cooling down moved to the end, original order otherwise
     */
    private function byCooldown(array $models): array
    {
        if (count($models) < 2) {
            return $models;
        }
        $cool = $this->cooldowns();
        $ready = array_values(array_filter($models, static fn ($m) => ($cool[$m] ?? 0) <= time()));
        $waiting = array_values(array_filter($models, static fn ($m) => ($cool[$m] ?? 0) > time()));

        return [...$ready, ...$waiting];
    }

    private function coolDown(string $model): void
    {
        $cool = array_filter($this->cooldowns(), static fn ($until) => $until > time());
        $cool[$model] = time() + self::COOLDOWN_SECONDS;
        @file_put_contents($this->cooldownFile(), (string) json_encode($cool), LOCK_EX);
    }

    /** @return array<string, int> */
    protected function cooldowns(): array
    {
        $raw = @file_get_contents($this->cooldownFile());
        $cool = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($cool) ? array_map('intval', $cool) : [];
    }

    protected function cooldownFile(): string
    {
        // Per OS user: environments sharing a host (and /tmp) must not fight over file permissions.
        return sys_get_temp_dir() . '/controleonline-mcp-ai-cooldown-' . (function_exists('posix_geteuid') ? posix_geteuid() : 0) . '.json';
    }

    protected function pause(int $attempt): void
    {
        sleep(2 * $attempt);
    }

    /** Tool failures go back to the model as text so it can recover or explain. */
    private function execute(callable $executor, string $name, mixed $args): string
    {
        try {
            return $executor($name, is_array($args) ? $args : []);
        } catch (\Throwable $e) {
            return 'ERROR: ' . $e->getMessage();
        }
    }

    /**
     * @param list<array{role: string, content: string}> $history
     * @return list<array{role: string, content: string}>
     */
    private function mergeSameRole(array $history): array
    {
        $out = [];
        foreach ($history as $m) {
            $last = array_key_last($out);
            if ($last !== null && $out[$last]['role'] === $m['role']) {
                $out[$last]['content'] .= "\n\n" . $m['content'];
                continue;
            }
            $out[] = $m;
        }

        return $out;
    }

    /** Gemini's OpenAPI subset rejects JSON-Schema keywords such as additionalProperties. */
    /** Keywords Gemini's function-declaration schema (OpenAPI subset) accepts; anything else is a 400. */
    private const GEMINI_SCHEMA_KEYS = [
        'type', 'format', 'title', 'description', 'nullable', 'enum', 'properties', 'required', 'items',
        'anyOf', 'minItems', 'maxItems', 'minimum', 'maximum', 'minLength', 'maxLength', 'pattern',
    ];

    private function geminiSchema(array $schema): array
    {
        $schema = json_decode((string) json_encode($schema), true) ?: [];
        $clean = static function (array $node) use (&$clean): array {
            if (isset($node['const']) && !isset($node['enum'])) {
                $node['enum'] = [$node['const']];
            }
            if (is_array($node['type'] ?? null)) {
                // ["string", "null"] -> type string + nullable
                $types = array_values(array_filter($node['type'], static fn ($t) => $t !== 'null'));
                if (count($types) < count($node['type'])) {
                    $node['nullable'] = true;
                }
                $node['type'] = $types[0] ?? 'string';
            }
            if (isset($node['enum']) && (!is_array($node['enum']) || array_filter($node['enum'], static fn ($v) => !is_string($v)) !== [])) {
                unset($node['enum']); // Gemini only takes string enums
            }
            $node = array_intersect_key($node, array_flip(self::GEMINI_SCHEMA_KEYS));
            if (is_array($node['properties'] ?? null)) {
                foreach ($node['properties'] as $name => $child) {
                    $node['properties'][$name] = is_array($child) ? $clean($child) : ['type' => 'string'];
                }
                if (is_array($node['required'] ?? null)) {
                    $node['required'] = array_values(array_filter($node['required'], static fn ($r) => isset($node['properties'][$r])));
                }
            }
            if (is_array($node['items'] ?? null)) {
                $node['items'] = $clean($node['items']);
            }
            if (is_array($node['anyOf'] ?? null)) {
                $node['anyOf'] = array_map(static fn ($n) => is_array($n) ? $clean($n) : $n, $node['anyOf']);
            }

            return $node;
        };

        return $clean($schema);
    }

    private function requireText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('AI provider returned empty content');
        }

        return $text;
    }

    /**
     * @param list<string> $headers
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    protected function httpJson(string $url, array $headers, array $body): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new \RuntimeException('Failed to encode AI request');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($errno !== 0 || !is_string($raw)) {
            throw new McpChatProviderUnavailableException('AI provider request failed (curl ' . $errno . ')', timedOut: $errno === CURLE_OPERATION_TIMEDOUT);
        }
        if (in_array($status, self::TRANSIENT_HTTP, true)) {
            throw new McpChatProviderUnavailableException('AI provider busy HTTP ' . $status . ': ' . mb_substr($raw, 0, 200));
        }
        // Gemini answers 404 for retired models ("no longer available"): move to the fallback model.
        if ($status === 404 && str_contains($url, 'generativelanguage.googleapis.com')) {
            throw new McpChatProviderUnavailableException('AI model unavailable HTTP 404: ' . mb_substr($raw, 0, 200), modelGone: true);
        }
        $decoded = json_decode($raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            throw new \RuntimeException('AI provider error HTTP ' . $status . ': ' . mb_substr($raw, 0, 300));
        }

        return $decoded;
    }
}
