<?php

declare(strict_types=1);

namespace ControleOnline\Service;

interface McpMarketplaceAuthorizationProviderInterface
{
    /** Resolve the authenticated user from the server-side security context. */
    public function currentUserId(): int;

    /** Re-check the server-authenticated user and current company access. */
    public function assertAllowed(McpMarketplaceOperationContext $context): void;
}
