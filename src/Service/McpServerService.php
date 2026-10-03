<?php

declare(strict_types=1);

namespace ControleOnline\Service;

/**
 * Minimal MCP JSON-RPC server (read-only tools).
 *
 * Supported methods:
 * - initialize
 * - tools/list
 * - tools/call (only tools marked read-only)
 * - resources/list
 * - ping
 */
class McpServerService
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME = 'controleonline-mcp';
    private const SERVER_VERSION = '1.0.0';

    public function __construct(
        private readonly bool $readOnly = true,
        private readonly ?McpCompanyScopeProviderInterface $companyScopeProvider = null,
        private readonly ?McpBusinessTools $businessTools = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function discovery(): array
    {
        return [
            'name' => self::SERVER_NAME,
            'version' => self::SERVER_VERSION,
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => new \stdClass(),
                'resources' => new \stdClass(),
            ],
            'readOnly' => $this->readOnly,
            'endpoint' => '/mcp',
            'transport' => 'http',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $id = $payload['id'] ?? null;
        $method = $payload['method'] ?? null;
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

        if ($method === null || $method === '') {
            return $this->error($id, -32600, 'Invalid Request');
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'notifications/initialized' => null,
                'ping' => new \stdClass(),
                'tools/list' => $this->toolsList(),
                'tools/call' => $this->toolsCall($params),
                'resources/list' => $this->resourcesList(),
                default => throw new \InvalidArgumentException("Method not found: {$method}"),
            };

            if ($method === 'notifications/initialized') {
                return ['jsonrpc' => '2.0'];
            }

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ];
        } catch (\InvalidArgumentException $e) {
            return $this->error($id, -32601, $e->getMessage());
        } catch (\Throwable $e) {
            // Keep database, tenant and entity details out of MCP responses.
            return $this->error($id, -32603, 'Internal server error');
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => new \stdClass(),
                'resources' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'instructions' => 'ControleOnline MCP server — read-only query tools only. Use tools/list then tools/call.',
        ];
    }

    /**
     * @return array{tools: list<array<string, mixed>>}
     */
    private function toolsList(): array
    {
        return [
            'tools' => [
                [
                    'name' => 'list_my_companies',
                    'description' => 'Lists only enabled companies the authenticated user can access. Use this to resolve the company before querying its data.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                ],
                [
                    'name' => 'health_check',
                    'description' => 'Returns API health and MCP server status (read-only).',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                ],
                ...McpBusinessTools::definitions(),
                [
                    'name' => 'list_capabilities',
                    'description' => 'Lists current MCP capabilities and read-only policy.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function toolsCall(array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ($name === '') {
            throw new \InvalidArgumentException('Tool name is required');
        }

        // Hard guard: no mutation tools in this delivery
        $mutationPrefixes = ['create_', 'update_', 'delete_', 'write_', 'set_', 'post_', 'put_', 'patch_'];
        foreach ($mutationPrefixes as $prefix) {
            if (str_starts_with((string) $name, $prefix)) {
                throw new \InvalidArgumentException('Mutation tools are disabled (read-only MCP)');
            }
        }

        $content = match ($name) {
            'health_check' => [
                'status' => 'ok',
                'server' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
                'readOnly' => $this->readOnly,
                'time' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            ],
            'list_my_companies' => $this->listMyCompanies(),
            'list_query_datasets', 'query_business_data' => $this->businessTools?->call((string) $name, $arguments)
                ?? throw new \RuntimeException('Business data tools are not configured'),
            'list_capabilities' => $this->discovery(),
            default => throw new \InvalidArgumentException("Unknown tool: {$name}"),
        };

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
            ],
            'isError' => false,
        ];
    }

    /** @return array{companies: list<array{id: int, name: string, alias: string}>} */
    private function listMyCompanies(): array
    {
        if ($this->companyScopeProvider === null) {
            throw new \RuntimeException('Company scope provider is not configured');
        }

        return ['companies' => $this->companyScopeProvider->listForCurrentUser()];
    }

    /**
     * @return array{resources: list<array<string, mixed>>}
     */
    private function resourcesList(): array
    {
        return [
            'resources' => [
                [
                    'uri' => 'controleonline://mcp/discovery',
                    'name' => 'MCP Discovery',
                    'description' => 'Server discovery document',
                    'mimeType' => 'application/json',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
