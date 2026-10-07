<?php

declare(strict_types=1);

namespace ControleOnline\Service;

final class McpBusinessTools
{
    public function __construct(
        private readonly ?McpReadQueryProviderInterface $provider = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'list_query_datasets',
                'description' => 'Lists read-only business data areas available to this authenticated user.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            ],
            [
                'name' => 'query_business_data',
                'description' => 'Reads a bounded, sanitized collection from an approved business dataset. Results are limited to companies this user can access in the current tenant.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'dataset' => ['type' => 'string', 'enum' => ['sales', 'invoices', 'products', 'inventory', 'wallets']],
                        'from' => ['type' => 'string', 'description' => 'Optional inclusive start date (YYYY-MM-DD).'],
                        'to' => ['type' => 'string', 'description' => 'Optional inclusive end date (YYYY-MM-DD).'],
                        'company_id' => ['type' => 'integer', 'minimum' => 1],
                        'company_role' => ['type' => 'string', 'enum' => ['customer', 'supplier', 'payer', 'receiver']],
                        'aggregate' => ['type' => 'boolean', 'description' => 'Return the count and total of closed sales instead of individual rows.'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    ],
                    'required' => ['dataset'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $arguments
     *  @return array<string, mixed>
     */
    public function call(string $name, array $arguments): array
    {
        return match ($name) {
            'list_query_datasets' => $this->listDatasets(),
            'query_business_data' => $this->queryBusinessData($arguments),
            default => throw new \InvalidArgumentException('Unknown business data tool'),
        };
    }

    /** @return array{datasets: list<array{name: string, description: string}>} */
    private function listDatasets(): array
    {
        return ['datasets' => $this->provider()->getDatasets()];
    }

    /** @param array<string, mixed> $arguments
     *  @return array<string, mixed>
     */
    private function queryBusinessData(array $arguments): array
    {
        $dataset = $arguments['dataset'] ?? null;
        if (!is_string($dataset) || !in_array($dataset, ['sales', 'invoices', 'products', 'inventory', 'wallets'], true)) {
            throw new \InvalidArgumentException('Unsupported dataset');
        }

        foreach (['from', 'to'] as $dateKey) {
            if (!isset($arguments[$dateKey])) {
                continue;
            }
            if (!is_string($arguments[$dateKey]) || preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $arguments[$dateKey]) !== 1) {
                throw new \InvalidArgumentException('Dates must use YYYY-MM-DD format');
            }
            [$year, $month, $day] = array_map('intval', explode('-', $arguments[$dateKey]));
            if (!checkdate($month, $day, $year)) {
                throw new \InvalidArgumentException('Date is not a valid calendar date');
            }
        }

        $companyId = $arguments['company_id'] ?? null;
        if ($companyId !== null && (!is_int($companyId) || $companyId < 1)) {
            throw new \InvalidArgumentException('company_id must be a positive integer');
        }

        $companyRole = $arguments['company_role'] ?? null;
        if ($companyRole !== null && !in_array($companyRole, ['customer', 'supplier', 'payer', 'receiver'], true)) {
            throw new \InvalidArgumentException('Unsupported company_role');
        }
        if ($companyRole !== null && $companyId === null) {
            throw new \InvalidArgumentException('company_id is required when company_role is set');
        }
        if (($dataset === 'sales' && in_array($companyRole, ['payer', 'receiver'], true))
            || ($dataset === 'invoices' && in_array($companyRole, ['customer', 'supplier'], true))
            || (in_array($dataset, ['products', 'inventory', 'wallets'], true)
                && ($companyRole !== null || isset($arguments['from']) || isset($arguments['to'])))) {
            throw new \InvalidArgumentException('These filters are not supported for this dataset');
        }

        $aggregate = $arguments['aggregate'] ?? false;
        if (!is_bool($aggregate) || ($aggregate && $dataset !== 'sales')) {
            throw new \InvalidArgumentException('Aggregate summaries are available only for sales');
        }

        $limit = $arguments['limit'] ?? 50;
        if (!is_int($limit) || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('limit must be between 1 and 100');
        }

        $rows = $this->provider()->query($dataset, [
            'from' => $arguments['from'] ?? null,
            'to' => $arguments['to'] ?? null,
            'company_id' => $companyId,
            'company_role' => $companyRole,
            'aggregate' => $aggregate,
            'limit' => $limit,
        ]);

        return $aggregate
            ? ['dataset' => $dataset, 'summary' => $rows[0] ?? ['count' => 0, 'total' => 0]]
            : ['dataset' => $dataset, 'count' => count($rows), 'rows' => $rows];
    }

    private function provider(): McpReadQueryProviderInterface
    {
        if ($this->provider === null) {
            throw new \RuntimeException('Read query provider is not configured');
        }

        return $this->provider;
    }
}
