<?php

declare(strict_types=1);

namespace ControleOnline\Mcp\Tests\Service;

use ControleOnline\Service\McpChatLlmClient;
use ControleOnline\Service\McpChatProviderUnavailableException;
use PHPUnit\Framework\TestCase;

/**
 * Scripted HTTP responses: each request records its body and pops the next response.
 */
final class ScriptedLlmClient extends McpChatLlmClient
{
    /** @var list<array{url: string, headers: list<string>, body: array<string, mixed>}> */
    public array $requests = [];

    /** @param list<array<string, mixed>|\Throwable> $responses */
    public function __construct(private array $responses)
    {
    }

    protected function httpJson(string $url, array $headers, array $body): array
    {
        // Round-trip like the wire does, so stdClass/{} vs [] issues show up.
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode((string) json_encode($body), true)];
        $this->requests[array_key_last($this->requests)]['raw'] = (string) json_encode($body);

        $next = array_shift($this->responses) ?? throw new \LogicException('No scripted response left');
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    protected function pause(int $attempt): void
    {
    }
}

final class McpChatLlmClientTest extends TestCase
{
    private const TOOLS = [[
        'name' => 'query_business_data',
        'description' => 'Query data',
        'schema' => ['type' => 'object', 'properties' => ['dataset' => ['type' => 'string']], 'additionalProperties' => false],
    ]];

    public function testClaudeToolLoopSendsResultsAndReturnsFinalText(): void
    {
        $client = new ScriptedLlmClient([
            ['stop_reason' => 'tool_use', 'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'query_business_data', 'input' => ['dataset' => 'orders']],
                ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'list_query_datasets', 'input' => []],
            ]],
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Você tem 42 pedidos.']]],
        ]);
        $calls = [];

        $answer = $client->run(
            'claude',
            ['base_url' => 'https://api.anthropic.com/v1', 'model' => 'claude-opus-5-5', 'api_key' => 'k'],
            'system',
            [['role' => 'user', 'content' => 'a'], ['role' => 'user', 'content' => 'quantos pedidos?']],
            self::TOOLS,
            function (string $name, array $args) use (&$calls): string {
                $calls[] = [$name, $args];
                if ($name === 'list_query_datasets') {
                    throw new \RuntimeException('boom');
                }

                return '{"count":42}';
            },
        );

        self::assertSame('Você tem 42 pedidos.', $answer);
        self::assertSame([['query_business_data', ['dataset' => 'orders']], ['list_query_datasets', []]], $calls);

        $first = $client->requests[0]['body'];
        self::assertArrayNotHasKey('temperature', $first);
        self::assertSame('claude-opus-5-5', $first['model']);
        self::assertSame('query_business_data', $first['tools'][0]['name']);
        // Consecutive user turns are merged.
        self::assertCount(1, $first['messages']);

        $second = $client->requests[1]['body']['messages'];
        self::assertSame('assistant', $second[1]['role']);
        self::assertSame('thinking', $second[1]['content'][0]['type'], 'assistant turn is passed back unchanged');
        self::assertStringContainsString('"input":{}', $client->requests[1]['raw'], 'empty tool input stays a JSON object');
        self::assertSame('user', $second[2]['role']);
        self::assertSame(['tu_1', 'tu_2'], array_column($second[2]['content'], 'tool_use_id'), 'all results in one user message');
        self::assertSame('ERROR: boom', $second[2]['content'][1]['content']);
    }

    public function testClaudeRefusalIsHandled(): void
    {
        $client = new ScriptedLlmClient([['stop_reason' => 'refusal', 'content' => []]]);

        $answer = $client->run('claude', ['base_url' => 'https://api.anthropic.com/v1', 'model' => 'm', 'api_key' => 'k'],
            's', [['role' => 'user', 'content' => 'x']], [], fn () => '');

        self::assertSame('Não posso ajudar com esse pedido.', $answer);
    }

    public function testOpenAiCompatibleToolLoop(): void
    {
        $client = new ScriptedLlmClient([
            ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'query_business_data', 'arguments' => '{"dataset":"orders"}']],
            ]]]]],
            ['choices' => [['message' => ['role' => 'assistant', 'content' => '42']]]],
        ]);

        $answer = $client->run('grok', ['base_url' => 'https://api.x.ai/v1', 'model' => 'grok-4', 'api_key' => 'k'],
            'sys', [['role' => 'user', 'content' => 'q']], self::TOOLS, fn (string $n, array $a) => 'rows:' . $a['dataset']);

        self::assertSame('42', $answer);
        self::assertStringEndsWith('/chat/completions', $client->requests[0]['url']);
        self::assertSame('function', $client->requests[0]['body']['tools'][0]['type']);
        $messages = $client->requests[1]['body']['messages'];
        self::assertSame(['system', 'user', 'assistant', 'tool'], array_column($messages, 'role'));
        self::assertSame('rows:orders', $messages[3]['content']);
    }

    public function testGeminiToolLoopStripsUnsupportedSchemaKeywords(): void
    {
        $client = new ScriptedLlmClient([
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => 'query_business_data', 'args' => []]],
            ]]]]],
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'ok']]]]]],
        ]);

        $answer = $client->run('gemini', ['base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'g', 'api_key' => 'k'],
            'sys', [['role' => 'user', 'content' => 'q']], self::TOOLS, fn () => 'done');

        self::assertSame('ok', $answer);
        $declaration = $client->requests[0]['body']['tools'][0]['functionDeclarations'][0];
        self::assertArrayNotHasKey('additionalProperties', $declaration['parameters']);
        $contents = $client->requests[1]['body']['contents'];
        self::assertSame('done', $contents[2]['parts'][0]['functionResponse']['response']['result']);
        self::assertStringContainsString('"args":{}', $client->requests[1]['raw']);
    }

    public function testGeminiFallsBackToNextModelWhenOverloaded(): void
    {
        $busy = static fn () => new McpChatProviderUnavailableException('AI provider busy HTTP 503');
        $client = new ScriptedLlmClient([
            $busy(),
            $busy(),
            ['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'list_query_datasets', 'args' => []]]]]]]],
            ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]],
        ]);

        $answer = $client->run(
            'gemini',
            ['base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-3.8-flash', 'api_key' => 'k', 'fallback_models' => 'gemini-3.5-flash'],
            'system',
            [['role' => 'user', 'content' => 'oi']],
            self::TOOLS,
            static fn () => '[]',
        );

        self::assertSame('ok', $answer);
        $models = array_map(static fn (array $r) => preg_replace('#^.*/models/([^:]+):.*$#', '$1', $r['url']), $client->requests);
        // Two attempts on the overloaded model, then the fallback, which stays first for the next round.
        self::assertSame(['gemini-3.8-flash', 'gemini-3.8-flash', 'gemini-3.5-flash', 'gemini-3.5-flash'], $models);
    }

    public function testRetiredGeminiModelSkipsStraightToFallback(): void
    {
        $client = new ScriptedLlmClient([
            new McpChatProviderUnavailableException('AI model unavailable HTTP 404', modelGone: true),
            ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]],
        ]);

        $answer = $client->run('gemini', ['base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-2.0-flash', 'api_key' => 'k'], 's', [['role' => 'user', 'content' => 'oi']], [], static fn () => '');

        self::assertSame('ok', $answer);
        self::assertCount(2, $client->requests);
        self::assertStringContainsString('/models/gemini-3.5-flash:', $client->requests[1]['url']);
    }

    public function testTransientErrorIsRetriedOnceThenReported(): void
    {
        $client = new ScriptedLlmClient([
            new McpChatProviderUnavailableException('AI provider busy HTTP 529'),
            new McpChatProviderUnavailableException('AI provider busy HTTP 529'),
        ]);

        try {
            $client->run('claude', ['base_url' => 'https://api.anthropic.com/v1', 'model' => 'claude-opus-5-5', 'api_key' => 'k'], 's', [['role' => 'user', 'content' => 'oi']], [], static fn () => '');
            self::fail('expected failure');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('unavailable after retries', $e->getMessage());
        }
        self::assertCount(2, $client->requests);
    }
}
