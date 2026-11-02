<?php

declare(strict_types=1);

namespace ControleOnline\Service;

interface McpMercadoLivreProviderInterface
{
    public function getStatus(int $companyId): array;
    public function getCurrentUser(int $companyId): array;
    public function searchItems(int $companyId, int $offset = 0, int $limit = 50): array;
    public function getItem(int $companyId, string $itemId): array;
    public function getItemDescription(int $companyId, string $itemId): array;
    public function getCategory(int $companyId, string $categoryId): array;
    public function getOrder(int $companyId, string $orderId): array;
    public function searchOrders(int $companyId, int $offset = 0, int $limit = 50, ?string $status = null): array;
    public function importOrders(int $companyId, int $limit = 20, ?string $status = null): array;
    public function importProducts(int $companyId, int $showcaseId, int $limit = 50): array;
}
