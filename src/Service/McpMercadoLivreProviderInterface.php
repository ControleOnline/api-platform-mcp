<?php

declare(strict_types=1);

namespace ControleOnline\\Service;

/**
 * Optional Mercado Livre integration exposed through MCP.
 * Implemented by the integration module where OAuth and tenant config live.
 */
interface McpMercadoLivreProviderInterface
{
    /** @return array<string, mixed> */
    public function getStatus(int $companyId): array;

    /** @return array<string, mixed> */
    public function getCurrentUser(int $companyId): array;

    /** @return array<string, mixed> */
    public function searchItems(int $companyId, int $offset = 0, int $limit = 50): array;

    /** @return array<string, mixed> */
    public function getItem(int $companyId, string $itemId): array;

    /** @return array<string, mixed> */
    public function getItemDescription(int $companyId, string $itemId): array;

    /** @return array<string, mixed> */
    public function getCategory(int $companyId, string $categoryId): array;

    /** @return array<string, mixed> */
    public function getOrder(int $companyId, string $orderId): array;

    /** @return array<string, mixed> */
    public function importProducts(int $companyId, int $showcaseId, int $limit = 50): array;
}
