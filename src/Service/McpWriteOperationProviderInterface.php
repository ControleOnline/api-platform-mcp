<?php

declare(strict_types=1);

namespace ControleOnline\Service;

interface McpWriteOperationProviderInterface
{
    /** @param array<string, mixed> $arguments
     *  @return array<string, mixed>
     */
    public function write(string $operation, array $arguments): array;
}
