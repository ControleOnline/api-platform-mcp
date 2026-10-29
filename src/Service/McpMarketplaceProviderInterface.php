<?php

declare(strict_types=1);

namespace ControleOnline\Service;

interface McpMarketplaceProviderInterface
{
    /** Stable lowercase marketplace slug, e.g. mercadolivre or shopee. */
    public function marketplace(): string;

    /** @return list<array<string, mixed>> MCP tool definitions with unique names. */
    public function toolDefinitions(): array;

    public function isWriteOperation(string $toolName): bool;

    /**
     * Resolve connection/resource ownership in the tenant and re-check
     * authorization immediately before every external write.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(
        string $toolName,
        McpMarketplaceOperationContext $context,
        array $arguments,
        McpMarketplaceAuthorizationProviderInterface $authorization,
    ): array;
}
