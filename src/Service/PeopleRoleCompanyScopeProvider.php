<?php

declare(strict_types=1);

namespace ControleOnline\Service;

use ControleOnline\Entity\People;

final class PeopleRoleCompanyScopeProvider implements McpCompanyScopeProviderInterface
{
    public function __construct(
        private readonly PeopleRoleService $peopleRoleService,
    ) {
    }

    public function listForCurrentUser(): array
    {
        $companies = [];

        foreach ($this->peopleRoleService->getAccessibleCompaniesForPeople() as $company) {
            if (!$company instanceof People || $company->getId() === null) {
                continue;
            }

            $companies[] = [
                'id' => (int) $company->getId(),
                'name' => (string) $company->getName(),
                'alias' => (string) $company->getAlias(),
            ];
        }

        return $companies;
    }
}
