<?php

declare(strict_types=1);

namespace ControleOnline\Service;

interface McpReadQueryProviderInterface
{
    /** @return list<array{name: string, description: string}> */
    public function getDatasets(): array;

    /** @param array<string, mixed> $filters
     *  @return list<array<string, scalar|null>>
     */
    public function query(string $dataset, array $filters): array;
}
