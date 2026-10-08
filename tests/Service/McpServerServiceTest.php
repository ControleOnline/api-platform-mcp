<?php

declare(strict_types=1);

namespace ControleOnline\Mcp\Tests\Service;

use ControleOnline\Service\McpServerService;
use ControleOnline\Service\McpCompanyScopeProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class McpServerServiceTest extends TestCase
{
    private McpServerService $service;

    protected function setUp(): void
    {
        $this->service = new McpServerService(true);
    }

    public function testDiscoveryContainsEndpoint(): void
    {
        $d = $this->service->discovery();
        $this->assertSame('/mcp', $d['endpoint']);
        $this->assertTrue($d['readOnly']);
        $this->assertSame('controleonline-mcp', $d['name']);
    }

    public function testInitialize(): void
    {
        $response = (new McpServerService(true, null, new \ControleOnline\Service\McpBusinessTools()))->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05'],
        ]);
        $this->assertArrayHasKey('result', $response);
        $this->assertSame('controleonline-mcp', $response['result']['serverInfo']['name']);
    }

    public function testToolsList(): void
    {
        $response = $this->service->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ]);
        $this->assertArrayHasKey('result', $response);
        $names = array_column($response['result']['tools'], 'name');
        $this->assertContains('health_check', $names);
        $this->assertContains('list_capabilities', $names);
        $this->assertContains('list_my_companies', $names);
        $this->assertContains('list_query_datasets', $names);
        $this->assertContains('query_business_data', $names);
    }

    public function testListMyCompaniesReturnsOnlyProviderProjection(): void
    {
        $scopeProvider = new class implements McpCompanyScopeProviderInterface {
            public function listForCurrentUser(): array
            {
                return [['id' => 12, 'name' => 'Empresa A', 'alias' => 'empresa-a']];
            }
        };
        $service = new McpServerService(true, $scopeProvider);

        $response = $service->handle([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => ['name' => 'list_my_companies', 'arguments' => []],
        ]);

        $this->assertArrayHasKey('result', $response);
        $payload = json_decode($response['result']['content'][0]['text'], true);
        $this->assertSame([
            'companies' => [['id' => 12, 'name' => 'Empresa A', 'alias' => 'empresa-a']],
        ], $payload);
        $this->assertStringNotContainsString('document', strtolower($response['result']['content'][0]['text']));
    }

    public function testInternalErrorsDoNotExposeTenantOrDataDetails(): void
    {
        $scopeProvider = new class implements McpCompanyScopeProviderInterface {
            public function listForCurrentUser(): array
            {
                throw new \RuntimeException('tenant-db-password-and-document');
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('MCP request failed', self::callback(static function (array $context): bool {
                self::assertSame('tools/call', $context['method']);
                self::assertSame('list_my_companies', $context['tool']);
                self::assertSame(\RuntimeException::class, $context['exception_class']);
                self::assertArrayNotHasKey('exception_message', $context);
                return true;
            }));
        $response = (new McpServerService(true, $scopeProvider, null, $logger))->handle([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => ['name' => 'list_my_companies', 'arguments' => []],
        ]);

        $this->assertSame('Internal server error', $response['error']['message']);
        $this->assertStringNotContainsString('tenant-db-password-and-document', json_encode($response));
    }

    public function testBusinessQueryUsesOnlyValidatedFiltersAndSanitizedProviderRows(): void
    {
        $queryProvider = new class implements \ControleOnline\Service\McpReadQueryProviderInterface {
            public array $received = [];

            public function getDatasets(): array
            {
                return [['name' => 'sales', 'description' => 'Sales orders']];
            }

            public function query(string $dataset, array $filters): array
            {
                $this->received = [$dataset, $filters];

                if ($filters['aggregate'] ?? false) {
                    return [['count' => 10, 'total' => 2000.0]];
                }

                return [['id' => 4, 'date' => '2026-10-02T10:00:00-03:00', 'total' => 125.5, 'type' => 'sale']];
            }
        };
        $service = new McpServerService(true, null, new \ControleOnline\Service\McpBusinessTools($queryProvider));

        $response = $service->handle([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'tools/call',
            'params' => [
                'name' => 'query_business_data',
                'arguments' => ['dataset' => 'sales', 'from' => '2026-10-02', 'company_id' => 12, 'company_role' => 'customer', 'limit' => 25],
            ],
        ]);

        $data = json_decode($response['result']['content'][0]['text'], true);
        self::assertSame('sales', $queryProvider->received[0]);
        self::assertSame(12, $queryProvider->received[1]['company_id']);
        self::assertSame(25, $queryProvider->received[1]['limit']);
        self::assertSame(125.5, $data['rows'][0]['total']);
        self::assertArrayNotHasKey('document', $data['rows'][0]);

        $summary = $service->handle([
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/call',
            'params' => [
                'name' => 'query_business_data',
                'arguments' => ['dataset' => 'sales', 'from' => '2026-10-02', 'to' => '2026-10-02', 'aggregate' => true],
            ],
        ]);
        $summaryData = json_decode($summary['result']['content'][0]['text'], true);
        self::assertSame(['count' => 10, 'total' => 2000], $summaryData['summary']);

        $inventory = $service->handle([
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'tools/call',
            'params' => [
                'name' => 'query_business_data',
                'arguments' => ['dataset' => 'inventory', 'company_id' => 12, 'limit' => 20],
            ],
        ]);
        self::assertArrayHasKey('result', $inventory, json_encode($inventory) ?: 'missing result');
        self::assertSame('inventory', $queryProvider->received[0]);
        self::assertSame(12, $queryProvider->received[1]['company_id']);
        self::assertSame(20, $queryProvider->received[1]['limit']);

        $wallets = $service->handle([
            'jsonrpc' => '2.0',
            'id' => 12,
            'method' => 'tools/call',
            'params' => [
                'name' => 'query_business_data',
                'arguments' => ['dataset' => 'wallets', 'company_id' => 12, 'limit' => 20],
            ],
        ]);
        self::assertArrayHasKey('result', $wallets, json_encode($wallets) ?: 'missing result');
        self::assertSame('wallets', $queryProvider->received[0]);
        self::assertSame(12, $queryProvider->received[1]['company_id']);
        self::assertSame(20, $queryProvider->received[1]['limit']);

        $orders = $service->handle([
            'jsonrpc' => '2.0',
            'id' => 13,
            'method' => 'tools/call',
            'params' => [
                'name' => 'query_business_data',
                'arguments' => ['dataset' => 'orders', 'from' => '2026-10-02', 'to' => '2026-10-02', 'company_id' => 12],
            ],
        ]);
        self::assertArrayHasKey('result', $orders, json_encode($orders) ?: 'missing result');
        self::assertSame('orders', $queryProvider->received[0]);
        self::assertSame(12, $queryProvider->received[1]['company_id']);

        foreach (['employees', 'clients', 'suppliers', 'salespeople', 'commissions', 'configs', 'devices', 'displays'] as $index => $dataset) {
            $response = $service->handle([
                'jsonrpc' => '2.0',
                'id' => 20 + $index,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'query_business_data',
                    'arguments' => ['dataset' => $dataset, 'company_id' => 12],
                ],
            ]);
            self::assertArrayHasKey('result', $response, json_encode($response) ?: 'missing result');
            self::assertSame($dataset, $queryProvider->received[0]);
        }

        $productionQueue = $service->handle([
            'jsonrpc' => '2.0',
            'id' => 30,
            'method' => 'tools/call',
            'params' => [
                'name' => 'query_business_data',
                'arguments' => ['dataset' => 'production_queue', 'from' => '2026-10-01', 'to' => '2026-10-02', 'company_id' => 12],
            ],
        ]);
        self::assertArrayHasKey('result', $productionQueue, json_encode($productionQueue) ?: 'missing result');
        self::assertSame('production_queue', $queryProvider->received[0]);
        self::assertSame('2026-10-01', $queryProvider->received[1]['from']);
    }

    public function testBusinessQueryRejectsUnboundedOrUnknownArguments(): void
    {
        $response = (new McpServerService(true, null, new \ControleOnline\Service\McpBusinessTools()))->handle([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => [
                'name' => 'query_business_data',
                'arguments' => ['dataset' => 'arbitrary', 'sql' => 'SELECT * FROM users'],
            ],
        ]);

        self::assertSame(-32601, $response['error']['code']);
        self::assertStringNotContainsString('SELECT', $response['error']['message']);
    }

    public function testToolsCallHealthCheck(): void
    {
        $response = $this->service->handle([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'health_check', 'arguments' => []],
        ]);
        $this->assertArrayHasKey('result', $response);
        $this->assertFalse($response['result']['isError']);
        $this->assertStringContainsString('ok', $response['result']['content'][0]['text']);
    }

    public function testMutationToolRejected(): void
    {
        $response = $this->service->handle([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'create_order', 'arguments' => []],
        ]);
        $this->assertArrayHasKey('error', $response);
        $this->assertStringContainsString('read-only', $response['error']['message']);
    }

    public function testUnknownMethod(): void
    {
        $response = $this->service->handle([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'foo/bar',
        ]);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame(-32601, $response['error']['code']);
    }
}
