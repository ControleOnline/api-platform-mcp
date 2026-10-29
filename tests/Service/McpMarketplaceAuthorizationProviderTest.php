<?php

declare(strict_types=1);

namespace ControleOnline\Mcp\Tests\Service;

use ControleOnline\Service\McpCompanyScopeProviderInterface;
use ControleOnline\Service\McpMarketplaceAccessDeniedException;
use ControleOnline\Service\McpMarketplaceAuthorizationProvider;
use ControleOnline\Service\McpMarketplaceOperationContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class McpMarketplaceAuthorizationProviderTest extends TestCase
{
    public function testIdentityAndCompanyScopeComeFromServerContext(): void
    {
        $user = new class implements UserInterface {
            public function getRoles(): array { return ['ROLE_HUMAN']; }
            public function eraseCredentials(): void {}
            public function getUserIdentifier(): string { return 'user-7'; }
            public function getId(): int { return 7; }
        };
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($token);
        $scope = new class implements McpCompanyScopeProviderInterface {
            public function listForCurrentUser(): array
            {
                return [['id' => 12, 'name' => 'Company A', 'alias' => 'company-a']];
            }
        };
        $provider = new McpMarketplaceAuthorizationProvider($storage, $scope);

        self::assertSame(7, $provider->currentUserId());
        $provider->assertAllowed(new McpMarketplaceOperationContext(7, 12, 'mercadolivre', 'marketplace_mercadolivre_list_products'));
    }

    public function testCompanyOutsideCurrentScopeIsDenied(): void
    {
        $user = new class implements UserInterface {
            public function getRoles(): array { return ['ROLE_HUMAN']; }
            public function eraseCredentials(): void {}
            public function getUserIdentifier(): string { return 'user-7'; }
            public function getId(): int { return 7; }
        };
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($token);
        $scope = new class implements McpCompanyScopeProviderInterface {
            public function listForCurrentUser(): array
            {
                return [['id' => 12, 'name' => 'Company A', 'alias' => 'company-a']];
            }
        };
        $provider = new McpMarketplaceAuthorizationProvider($storage, $scope);

        $this->expectException(McpMarketplaceAccessDeniedException::class);
        $provider->assertAllowed(new McpMarketplaceOperationContext(7, 999, 'mercadolivre', 'marketplace_mercadolivre_list_products'));
    }
}
