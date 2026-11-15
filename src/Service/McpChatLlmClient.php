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
    private const TIMEOUT_SECONDS = 180;

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
            $decoded = $this->httpJson($baseUrl . '/chat/completions', $headers, $body + ['messages' => $messages]);
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
            $decoded = $this->httpJson($baseUrl . '/messages', $headers, $body + ['messages' => $messages]);
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
        $url = $baseUrl . '/models/' . rawurlencode((string) $config['model']) . ':generateContent?key=' . rawurlencode((string) $config['api_key']);

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $decoded = $this->httpJson($url, ['Content-Type: application/json'], $body + ['contents' => $contents]);
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
    private function geminiSchema(array $schema): array
    {
        $schema = json_decode((string) json_encode($schema), true) ?: [];
        $clean = static function (array $node) use (&$clean): array {
            unset($node['additionalProperties'], $node['$schema']);
            foreach ($node as $k => $v) {
                if (is_array($v)) {
                    $node[$k] = $clean($v);
                }
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
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($errno !== 0 || !is_string($raw)) {
            throw new \RuntimeException('AI provider request failed (curl ' . $errno . ')');
        }
        $decoded = json_decode($raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            throw new \RuntimeException('AI provider error HTTP ' . $status . ': ' . mb_substr($raw, 0, 300));
        }

        return $decoded;
    }
}
