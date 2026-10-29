<?php

declare(strict_types=1);

namespace ControleOnline\Mcp\Tests\Service;

use ControleOnline\Service\McpBusinessTools;
use ControleOnline\Service\McpCompanyScopeProviderInterface;
use ControleOnline\Service\McpMarketplaceAuthorizationProviderInterface;
use ControleOnline\Service\McpMarketplaceOperationContext;
use ControleOnline\Service\McpMarketplaceProviderInterface;
use ControleOnline\Service\McpMarketplaceTools;
use ControleOnline\Service\McpServerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class McpMarketplaceServerIntegrationTest extends TestCase
{
    public function testMarketplaceReadToolIsDiscoveredAndDispatched(): void
    {
        $authorization = new class implements McpMarketplaceAuthorizationProviderInterface {
            public function currentUserId(): int { return 7; }
            public function assertAllowed(McpMarketplaceOperationContext $context): void
            {
                self::assertSame(7, $context->userId);
                self::assertSame(12, $context->companyId);
            }
        };
        $provider = new class implements McpMarketplaceProviderInterface {
            public function marketplace(): string { return 'mercadolivre'; }
            public function toolDefinitions(): array
            {
                return [[
                    'name' => 'marketplace_mercadolivre_list_products',
                    'description' => 'List products',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => ['company_id' => ['type' => 'integer']],
                        'required' => ['company_id'],
                        'additionalProperties' => false,
                    ],
                ]];
            }
            public function isWriteOperation(string $toolName): bool { return false; }
            public function execute(
                string $toolName,
                McpMarketplaceOperationContext $context,
                array $arguments,
                McpMarketplaceAuthorizationProviderInterface $authorization,
            ): array {
                return ['products' => [['id' => 'ML-10']], 'company_id' => $context->companyId];
            }
        };
        $scope = new class implements McpCompanyScopeProviderInterface {
            public function listForCurrentUser(): array
            {
                return [['id' => 12, 'name' => 'Company A', 'alias' => 'company-a']];
            }
        };
        $tools = new McpMarketplaceTools([$provider], $authorization, $scope, new NullLogger());
        $server = new McpServerService(true, null, new McpBusinessTools(), null, $tools);

        $listed = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        self::assertContains('marketplace_mercadolivre_list_products', array_column($listed['result']['tools'], 'name'));

        $called = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'marketplace_mercadolivre_list_products',
                'arguments' => ['company_id' => 12],
            ],
        ]);
        self::assertArrayHasKey('result', $called);
        $data = json_decode($called['result']['content'][0]['text'], true);
        self::assertSame(12, $data['company_id']);
        self::assertSame('ML-10', $data['products'][0]['id']);
    }
}
