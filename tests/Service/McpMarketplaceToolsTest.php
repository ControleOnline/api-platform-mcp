<?php

declare(strict_types=1);

namespace ControleOnline\Mcp\Tests\Service;

use ControleOnline\Service\McpCompanyScopeProviderInterface;
use ControleOnline\Service\McpMarketplaceAccessDeniedException;
use ControleOnline\Service\McpMarketplaceAuthorizationProviderInterface;
use ControleOnline\Service\McpMarketplaceOperationContext;
use ControleOnline\Service\McpMarketplaceProviderInterface;
use ControleOnline\Service\McpMarketplaceTools;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class McpMarketplaceToolsTest extends TestCase
{
    public function testWritesAreScopedReauthorizedAndAuditedWithoutArguments(): void
    {
        $authorization = new class implements McpMarketplaceAuthorizationProviderInterface {
            public int $checks = 0;
            public function currentUserId(): int { return 7; }
            public function assertAllowed(McpMarketplaceOperationContext $context): void
            {
                ++$this->checks;
                self::assertSame(7, $context->userId);
                self::assertSame(12, $context->companyId);
            }
        };
        $provider = new class implements McpMarketplaceProviderInterface {
            public int $calls = 0;
            public function marketplace(): string { return 'mercadolivre'; }
            public function toolDefinitions(): array
            {
                return [
                    ['name' => 'marketplace_mercadolivre_list_products', 'description' => 'List products', 'inputSchema' => ['type' => 'object', 'properties' => ['company_id' => ['type' => 'integer']]]],
                    ['name' => 'marketplace_mercadolivre_update_price', 'description' => 'Update price', 'inputSchema' => ['type' => 'object', 'properties' => ['company_id' => ['type' => 'integer']]]],
                ];
            }
            public function isWriteOperation(string $toolName): bool { return $toolName === 'marketplace_mercadolivre_update_price'; }
            public function execute(string $toolName, McpMarketplaceOperationContext $context, array $arguments, McpMarketplaceAuthorizationProviderInterface $authorization): array
            {
                if ($this->isWriteOperation($toolName)) $authorization->assertAllowed($context);
                ++$this->calls;
                return ['success' => true, 'external_resource_id' => 'ML-900'];
            }
        };
        $scope = new class implements McpCompanyScopeProviderInterface {
            public function listForCurrentUser(): array { return [['id' => 12, 'name' => 'Company A', 'alias' => 'company-a']]; }
        };
        $events = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('info')->with(
            'MCP marketplace operation',
            self::callback(static function (array $context) use (&$events): bool {
                $events[] = $context;
                self::assertArrayNotHasKey('arguments', $context);
                self::assertArrayNotHasKey('access_token', $context);
                self::assertArrayNotHasKey('refresh_token', $context);
                self::assertArrayNotHasKey('exception_message', $context);
                return true;
            }),
        );

        $tools = new McpMarketplaceTools([$provider], $authorization, $scope, $logger);
        self::assertSame(['marketplace_mercadolivre_list_products'], array_column($tools->definitions(false), 'name'));
        self::assertCount(2, $tools->definitions(true));
        $result = $tools->call('marketplace_mercadolivre_update_price', [
            'company_id' => 12, 'connection_id' => 'connection-5', 'resource_type' => 'listing',
            'resource_id' => 'ML-900', 'price' => 25.50,
        ]);

        self::assertTrue($result['success']);
        self::assertSame(1, $provider->calls);
        self::assertSame(2, $authorization->checks, 'Adapter must re-authorize immediately before external write.');
        self::assertSame('started', $events[0]['outcome']);
        self::assertSame('success', $events[1]['outcome']);
        self::assertSame('ML-900', $events[1]['external_resource_id']);
        self::assertSame(7, $events[1]['user_id']);
        self::assertSame('connection-5', $events[1]['connection_id']);
    }

    public function testCompanyOutsideScopeIsDeniedBeforeProviderExecution(): void
    {
        $authorization = new class implements McpMarketplaceAuthorizationProviderInterface {
            public function currentUserId(): int { return 7; }
            public function assertAllowed(McpMarketplaceOperationContext $context): void
            {
                self::fail('Authorization must not run for an inaccessible company.');
            }
        };
        $provider = new class implements McpMarketplaceProviderInterface {
            public function marketplace(): string { return 'mercadolivre'; }
            public function toolDefinitions(): array
            {
                return [['name' => 'marketplace_mercadolivre_list_products', 'description' => 'List products', 'inputSchema' => ['type' => 'object', 'properties' => ['company_id' => ['type' => 'integer']]]]];
            }
            public function isWriteOperation(string $toolName): bool { return false; }
            public function execute(string $toolName, McpMarketplaceOperationContext $context, array $arguments, McpMarketplaceAuthorizationProviderInterface $authorization): array
            {
                self::fail('Provider must not execute for an inaccessible company.');
            }
        };
        $scope = new class implements McpCompanyScopeProviderInterface {
            public function listForCurrentUser(): array { return [['id' => 12, 'name' => 'Company A', 'alias' => 'company-a']]; }
        };
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('MCP marketplace operation', self::callback(static fn (array $context): bool => $context['outcome'] === 'started'));
        $logger->expects(self::once())->method('warning')->with('MCP marketplace operation', self::callback(static fn (array $context): bool => $context['outcome'] === 'denied' && $context['exception_class'] === McpMarketplaceAccessDeniedException::class));

        $tools = new McpMarketplaceTools([$provider], $authorization, $scope, $logger);
        $this->expectException(McpMarketplaceAccessDeniedException::class);
        $tools->call('marketplace_mercadolivre_list_products', ['company_id' => 999]);
    }

    public function testToolsFailClosedWhenSecurityOrAuditDependenciesAreMissing(): void
    {
        $provider = new class implements McpMarketplaceProviderInterface {
            public function marketplace(): string { return 'mercadolivre'; }
            public function toolDefinitions(): array
            {
                return [['name' => 'marketplace_mercadolivre_list_products', 'description' => 'List products', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]]];
            }
            public function isWriteOperation(string $toolName): bool { return false; }
            public function execute(string $toolName, McpMarketplaceOperationContext $context, array $arguments, McpMarketplaceAuthorizationProviderInterface $authorization): array
            {
                self::fail('Provider must not be exposed without authorization and audit services.');
            }
        };
        $tools = new McpMarketplaceTools([$provider]);
        self::assertFalse($tools->isAvailable());
        self::assertSame([], $tools->definitions(true));
        self::assertFalse($tools->supportsTool('marketplace_mercadolivre_list_products'));
    }

    public function testClientCannotSupplyCredentialsToMarketplaceTools(): void
    {
        $authorization = new class implements McpMarketplaceAuthorizationProviderInterface {
            public function currentUserId(): int { return 7; }
            public function assertAllowed(McpMarketplaceOperationContext $context): void {}
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
                    ],
                ]];
            }
            public function isWriteOperation(string $toolName): bool { return false; }
            public function execute(string $toolName, McpMarketplaceOperationContext $context, array $arguments, McpMarketplaceAuthorizationProviderInterface $authorization): array
            {
                self::fail('Client-supplied credentials must never reach a provider.');
            }
        };
        $scope = new class implements McpCompanyScopeProviderInterface {
            public function listForCurrentUser(): array { return [['id' => 12, 'name' => 'Company A', 'alias' => 'company-a']]; }
        };
        $tools = new McpMarketplaceTools([$provider], $authorization, $scope, new \Psr\Log\NullLogger());
        $this->expectException(\InvalidArgumentException::class);
        $tools->call('marketplace_mercadolivre_list_products', ['company_id' => 12, 'access_token' => 'do-not-accept']);
    }
}
