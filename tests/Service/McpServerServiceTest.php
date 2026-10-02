<?php

declare(strict_types=1);

namespace ControleOnline\Mcp\Tests\Service;

use ControleOnline\Service\McpServerService;
use PHPUnit\Framework\TestCase;

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
        $response = $this->service->handle([
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
