<?php

declare(strict_types=1);

namespace ControleOnline\Service;

interface McpCompanyScopeProviderInterface
{
    /** @return list<array{id: int, name: string, alias: string}> */
    public function listForCurrentUser(): array;
}
