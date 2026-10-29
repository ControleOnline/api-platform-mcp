<?php

declare(strict_types=1);

namespace ControleOnline\Service;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class McpMarketplaceAuthorizationProvider implements McpMarketplaceAuthorizationProviderInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly McpCompanyScopeProviderInterface $companyScopeProvider,
    ) {
    }

    public function currentUserId(): int
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!is_object($user) || !method_exists($user, 'getId')) {
            throw new McpMarketplaceAccessDeniedException('Authenticated user is unavailable');
        }

        $id = $user->getId();
        if (!is_numeric($id) || (int) $id < 1) {
            throw new McpMarketplaceAccessDeniedException('Authenticated user is invalid');
        }

        return (int) $id;
    }

    public function assertAllowed(McpMarketplaceOperationContext $context): void
    {
        if ($context->userId !== $this->currentUserId()) {
            throw new McpMarketplaceAccessDeniedException('Authenticated user changed during marketplace operation');
        }

        foreach ($this->companyScopeProvider->listForCurrentUser() as $company) {
            if (is_array($company) && ($company['id'] ?? null) === $context->companyId) {
                return;
            }
        }

        throw new McpMarketplaceAccessDeniedException('Marketplace company access denied');
    }
}
