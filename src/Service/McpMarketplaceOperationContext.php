<?php

declare(strict_types=1);

namespace ControleOnline\Service;

final readonly class McpMarketplaceOperationContext
{
    public function __construct(
        public int $userId,
        public int $companyId,
        public string $marketplace,
        public string $operation,
        public int|string|null $connectionId = null,
        public ?string $resourceType = null,
        public int|string|null $resourceId = null,
    ) {
        if ($userId < 1 || $companyId < 1 || trim($operation) === ''
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $marketplace) !== 1) {
            throw new \InvalidArgumentException('Invalid marketplace operation context');
        }
        if ((is_int($connectionId) && $connectionId < 1)
            || (is_string($connectionId) && trim($connectionId) === '')
            || (is_string($resourceType) && trim($resourceType) === '')
            || (is_int($resourceId) && $resourceId < 1)
            || (is_string($resourceId) && trim($resourceId) === '')) {
            throw new \InvalidArgumentException('Invalid connection or resource identifier');
        }
    }
}
