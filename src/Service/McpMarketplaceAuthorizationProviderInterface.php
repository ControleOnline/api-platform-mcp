<?php

declare(strict_types=1);

namespace ControleOnline\Service;

interface McpMarketplaceAuthorizationProviderInterface
{
    /** Resolve the authenticated user from the server-side security context. */
    public function currentUserId(): int;

    /** Re-check current access to the company, connection, operation and resource. */
    public function assertAllowed(McpMarketplaceOperationContext $context): void;
}
