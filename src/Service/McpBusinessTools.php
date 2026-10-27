<?php

declare(strict_types=1);

namespace ControleOnline\Service;

final class McpBusinessTools
{
    private const DATASETS = [
        'sales', 'orders', 'invoices', 'products', 'inventory', 'wallets',
        'employees', 'clients', 'suppliers', 'salespeople', 'commissions',
        'configs', 'devices', 'displays', 'production_queue',
    ];

    public function __construct(
        private readonly ?McpReadQueryProviderInterface $provider = null,
        private readonly ?McpWriteOperationProviderInterface $writeProvider = null,
    ) {
    }

    public function canWrite(): bool
    {
        return $this->writeProvider !== null;
    }

    /** @return list<array<string, mixed>> */
    public static function definitions(bool $includeWrites = false): array
    {
        $definitions = [
            [
                'name' => 'list_query_datasets',
                'description' => 'Lists business data areas available to this authenticated user.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            ],
            [
                'name' => 'query_business_data',
                'description' => 'Retrieve complete business records and relationships allowed for the authenticated user. Orders, sales, and invoices include linked counterparties and their details; linked people are returned only when authorized through an in-scope order/invoice or an enabled people_link. Use record_id to retrieve a specific order (for example an order number) while the API still applies securityFilter and company scope. Use limit and offset to page through all rows until has_more is false. Set from/to for time-based questions. Set aggregate=true for database-computed summaries. Every query is restricted to companies this user can access in the current tenant; API keys, passwords, credentials, tokens, and charge capabilities are removed.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'dataset' => ['type' => 'string', 'enum' => self::DATASETS],
                        'from' => ['type' => 'string', 'description' => 'Optional inclusive start date (YYYY-MM-DD).'],
                        'to' => ['type' => 'string', 'description' => 'Optional inclusive end date (YYYY-MM-DD).'],
                        'company_id' => ['type' => 'integer', 'minimum' => 1],
                        'company_role' => ['type' => 'string', 'enum' => ['customer', 'supplier', 'payer', 'receiver']],
                        'record_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Exact record ID filter, especially useful for looking up a specific order or invoice. It never bypasses company or security filters.'],
                        'aggregate' => ['type' => 'boolean', 'description' => 'Return database-computed counts and applicable sums instead of individual rows.'],
                        'group_by' => [
                            'type' => 'array',
                            'description' => 'Optional grouping for aggregate results. Supported dimensions depend on the dataset: day, month, type, company_id, product, wallet, active, status, or queue.',
                            'items' => ['type' => 'string', 'enum' => ['day', 'month', 'type', 'company_id', 'product', 'wallet', 'active', 'status', 'queue']],
                            'uniqueItems' => true,
                            'maxItems' => 3,
                        ],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                        'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Pagination offset. Continue increasing it by limit while has_more is true.'],
                    ],
                    'required' => ['dataset'],
                    'additionalProperties' => false,
                ],
            ],
        ];

        if ($includeWrites) {
            $definitions[] = [
                'name' => 'write_business_data',
                'description' => 'Create or update company device configuration, products, or purchase, sale, and transfer orders for a company the authenticated user is authorized to manage. Database stock triggers process order changes. The API applies its normal security and business rules.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'operation' => ['type' => 'string', 'enum' => ['upsert_company_config', 'create_product', 'update_product', 'create_stock_order']],
                        'company_id' => ['type' => 'integer', 'minimum' => 1],
                        'record_id' => ['type' => 'integer', 'minimum' => 1],
                        'payload' => [
                            'type' => 'object',
                            'description' => 'For upsert_company_config: config_key=devices and config_value object. For create_product/update_product: only product, sku, type, price, productCondition, description, active and productUnitId. For create_stock_order: order_type (purchase/sale/transfer), partner_id for purchase/sale, destination_company_id for transfer, and items with product_id, positive quantity and, for transfer, in_inventory_id/out_inventory_id.',
                            'properties' => [
                                'config_key' => ['type' => 'string', 'enum' => ['devices']],
                                'config_value' => ['type' => 'object'],
                                'product' => ['type' => 'string'],
                                'sku' => ['type' => 'string'],
                                'type' => ['type' => 'string'],
                                'price' => ['type' => 'number', 'minimum' => 0],
                                'productCondition' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'active' => ['type' => 'boolean'],
                                'productUnitId' => ['type' => 'integer', 'minimum' => 1],
                                'order_type' => ['type' => 'string', 'enum' => ['purchase', 'sale', 'transfer']],
                                'partner_id' => ['type' => 'integer', 'minimum' => 1],
                                'destination_company_id' => ['type' => 'integer', 'minimum' => 1],
                                'items' => [
                                    'type' => 'array',
                                    'minItems' => 1,
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'product_id' => ['type' => 'integer', 'minimum' => 1],
                                            'quantity' => ['type' => 'number', 'exclusiveMinimum' => 0],
                                            'comment' => ['type' => 'string'],
                                            'in_inventory_id' => ['type' => 'integer', 'minimum' => 1],
                                            'out_inventory_id' => ['type' => 'integer', 'minimum' => 1],
                                        ],
                                        'required' => ['product_id', 'quantity'],
                                        'additionalProperties' => false,
                                    ],
                                ],
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                    'required' => ['operation', 'company_id', 'payload'],
                    'additionalProperties' => false,
                ],
            ];
        }

        return $definitions;
    }

    /** @param array<string, mixed> $arguments
     *  @return array<string, mixed>
     */
    public function call(string $name, array $arguments): array
    {
        return match ($name) {
            'list_query_datasets' => $this->listDatasets(),
            'query_business_data' => $this->queryBusinessData($arguments),
            'write_business_data' => $this->writeBusinessData($arguments),
            default => throw new \InvalidArgumentException('Unknown business data tool'),
        };
    }

    /** @param array<string, mixed> $arguments
     *  @return array<string, mixed>
     */
    private function writeBusinessData(array $arguments): array
    {
        $operation = $arguments['operation'] ?? null;
        $companyId = $arguments['company_id'] ?? null;
        $payload = $arguments['payload'] ?? null;
        if (!is_string($operation) || !in_array($operation, ['upsert_company_config', 'create_product', 'update_product', 'create_stock_order'], true)) {
            throw new \InvalidArgumentException('Unsupported write operation');
        }

        if (!is_int($companyId) || $companyId < 1 || !is_array($payload)) {
            throw new \InvalidArgumentException('company_id and payload are required');
        }
        if ($operation === 'update_product' && (!is_int($arguments['record_id'] ?? null) || $arguments['record_id'] < 1)) {
            throw new \InvalidArgumentException('record_id is required for update_product');
        }
        if ($this->writeProvider === null) {
            throw new \RuntimeException('Write operations are not configured');
        }

        return $this->writeProvider->write($operation, $arguments);
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
        if (!is_string($dataset) || !in_array($dataset, self::DATASETS, true)) {
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

        $recordId = $arguments['record_id'] ?? null;
        if ($recordId !== null && (!is_int($recordId) || $recordId < 1)) {
            throw new \InvalidArgumentException('record_id must be a positive integer');
        }
        if ($recordId !== null && !in_array($dataset, ['sales', 'orders', 'invoices', 'products'], true)) {
            throw new \InvalidArgumentException('record_id is not supported for this dataset');
        }

        $companyRole = $arguments['company_role'] ?? null;
        if ($companyRole !== null && !in_array($companyRole, ['customer', 'supplier', 'payer', 'receiver'], true)) {
            throw new \InvalidArgumentException('Unsupported company_role');
        }
        if ($companyRole !== null && $companyId === null) {
            throw new \InvalidArgumentException('company_id is required when company_role is set');
        }
        if ((in_array($dataset, ['sales', 'orders'], true) && in_array($companyRole, ['payer', 'receiver'], true))
            || ($dataset === 'invoices' && in_array($companyRole, ['customer', 'supplier'], true))
            || (in_array($dataset, ['products', 'inventory', 'wallets', 'employees', 'clients', 'suppliers', 'salespeople', 'commissions', 'configs', 'devices', 'displays'], true)
                && ($companyRole !== null || isset($arguments['from']) || isset($arguments['to'])))) {
            throw new \InvalidArgumentException('These filters are not supported for this dataset');
        }

        $aggregate = $arguments['aggregate'] ?? false;
        if (!is_bool($aggregate)) {
            throw new \InvalidArgumentException('aggregate must be a boolean');
        }
        $groupBy = $arguments['group_by'] ?? [];
        if (!is_array($groupBy) || array_filter($groupBy, static fn (mixed $dimension): bool => !is_string($dimension)) !== []) {
            throw new \InvalidArgumentException('group_by must be a list of dimensions');
        }
        $groupBy = array_values(array_unique($groupBy));
        $aggregatable = [
            'sales', 'orders', 'invoices', 'products', 'inventory', 'wallets',
            'employees', 'clients', 'suppliers', 'salespeople', 'configs', 'devices', 'displays', 'production_queue',
        ];
        if ($aggregate && !in_array($dataset, $aggregatable, true)) {
            throw new \InvalidArgumentException('Aggregate summaries are not available for this dataset');
        }
        $groupable = match ($dataset) {
            'sales', 'orders', 'invoices' => ['day', 'month', 'type', 'company_id'],
            'products' => ['type', 'active', 'company_id'],
            'inventory' => ['product', 'company_id'],
            'wallets' => ['wallet', 'company_id'],
            'employees', 'clients', 'suppliers', 'salespeople', 'commissions' => ['company_id'],
            'configs' => ['company_id'],
            'devices', 'displays' => ['company_id', 'type'],
            'production_queue' => ['day', 'company_id', 'status', 'queue'],
            default => [],
        };
        if (count($groupBy) > 3 || array_diff($groupBy, $groupable) !== []) {
            throw new \InvalidArgumentException('Unsupported grouping dimension for this dataset');
        }
        if ($groupBy !== [] && !$aggregate) {
            throw new \InvalidArgumentException('group_by requires aggregate=true');
        }

        $limit = $arguments['limit'] ?? 50;
        if (!is_int($limit) || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('limit must be between 1 and 100');
        }
        $offset = $arguments['offset'] ?? 0;
        if (!is_int($offset) || $offset < 0 || $offset > 1000000) {
            throw new \InvalidArgumentException('offset must be between 0 and 1000000');
        }

        $rows = $this->provider()->query($dataset, [
            'from' => $arguments['from'] ?? null,
            'to' => $arguments['to'] ?? null,
            'company_id' => $companyId,
            'company_role' => $companyRole,
            'record_id' => $recordId,
            'aggregate' => $aggregate,
            'group_by' => $groupBy,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        if ($aggregate) {
            if ($groupBy !== []) {
                return ['dataset' => $dataset, 'groups' => $rows, 'group_by' => $groupBy]
                    + ($rows === [] ? ['hint' => 'No rows matched. Confirm the date range and choose an accessible company from list_my_companies.'] : []);
            }
            $summary = $rows[0] ?? ['count' => 0];
            return ['dataset' => $dataset, 'summary' => $summary] + ((int) $summary['count'] === 0
                ? ['hint' => 'No rows matched. Confirm the date range and choose an accessible company from list_my_companies.']
                : []);
        }

        return ['dataset' => $dataset, 'count' => count($rows), 'rows' => $rows, 'limit' => $limit, 'offset' => $offset, 'has_more' => count($rows) === $limit, 'next_offset' => $offset + count($rows)] + ($rows === []
            ? ['hint' => 'No rows matched. Confirm the date range and choose an accessible company from list_my_companies.']
            : []);
    }

    private function provider(): McpReadQueryProviderInterface
    {
        if ($this->provider === null) {
            throw new \RuntimeException('Read query provider is not configured');
        }

        return $this->provider;
    }
}
