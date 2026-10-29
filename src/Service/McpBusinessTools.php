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
        private readonly ?McpLinkedInProviderInterface $linkedInProvider = null,
        private readonly ?McpMercadoLivreProviderInterface $mercadoLivreProvider = null,
    ) {
    }

    public function canWrite(): bool
    {
        return $this->writeProvider !== null;
    }

    public function canLinkedIn(): bool
    {
        return $this->linkedInProvider !== null;
    }

    public function canLinkedInWrite(): bool
    {
        return $this->linkedInProvider !== null;
    }

    public function canMercadoLivre(): bool
    {
        return $this->mercadoLivreProvider !== null;
    }

    /** @return list<array<string, mixed>> */
    public static function mercadoLivreDefinitions(bool $includeWrite = true): array
    {
        $company = ['company_id' => ['type' => 'integer', 'minimum' => 1]];
        $specs = [
            ['mercadolivre_status', 'Returns the connected Mercado Livre status without exposing tokens.', $company, ['company_id']],
            ['mercadolivre_get_current_user', 'Reads the connected Mercado Livre seller identity.', $company, ['company_id']],
            ['mercadolivre_search_items', 'Lists item ids for the connected seller with offset pagination.', $company + ['offset' => ['type' => 'integer', 'minimum' => 0], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], ['company_id']],
            ['mercadolivre_get_item', 'Reads a Mercado Livre listing by item id.', $company + ['item_id' => ['type' => 'string', 'minLength' => 1]], ['company_id', 'item_id']],
            ['mercadolivre_get_item_description', 'Reads a Mercado Livre listing description.', $company + ['item_id' => ['type' => 'string', 'minLength' => 1]], ['company_id', 'item_id']],
            ['mercadolivre_get_category', 'Reads Mercado Livre category metadata.', $company + ['category_id' => ['type' => 'string', 'minLength' => 1]], ['company_id', 'category_id']],
            ['mercadolivre_get_order', 'Reads an order visible to the connected seller.', $company + ['order_id' => ['type' => 'string', 'minLength' => 1]], ['company_id', 'order_id']],
        ];
        $definitions = [];
        foreach ($specs as [$name, $description, $properties, $required]) {
            $definitions[] = ['name' => $name, 'description' => $description, 'inputSchema' => [
                'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false,
            ]];
        }
        if ($includeWrite) {
            $definitions[] = ['name' => 'mercadolivre_import_products',
                'description' => 'Imports or updates products from the connected Mercado Livre seller into the selected local showcase. Does not publish or edit remote listings.',
                'inputSchema' => ['type' => 'object', 'properties' => $company + [
                    'showcase_id' => ['type' => 'integer', 'minimum' => 1],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                ], 'required' => ['company_id', 'showcase_id'], 'additionalProperties' => false]];
        }
        return $definitions;
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
                'description' => 'Use this tool to retrieve business data, not only metadata. First call list_my_companies to resolve the company, then query the chosen dataset with that company_id. Always set from and to for a time-based question. Set aggregate=true to get database-computed counts and sums without loading detail rows; optionally pass group_by to split the result by an allowed dimension. Use employees, clients, suppliers, and salespeople for linked people; use commissions for salesperson rates the user is authorized to manage. Use configs and devices for device setup metadata, displays for production screens, and production_queue for preparation status. A request only returns data for companies this user can access in the current tenant.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'dataset' => ['type' => 'string', 'enum' => self::DATASETS],
                        'from' => ['type' => 'string', 'description' => 'Optional inclusive start date (YYYY-MM-DD).'],
                        'to' => ['type' => 'string', 'description' => 'Optional inclusive end date (YYYY-MM-DD).'],
                        'company_id' => ['type' => 'integer', 'minimum' => 1],
                        'company_role' => ['type' => 'string', 'enum' => ['customer', 'supplier', 'payer', 'receiver']],
                        'aggregate' => ['type' => 'boolean', 'description' => 'Return database-computed counts and applicable sums instead of individual rows.'],
                        'group_by' => [
                            'type' => 'array',
                            'description' => 'Optional grouping for aggregate results. Supported dimensions depend on the dataset: day, month, type, company_id, product, wallet, active, status, or queue.',
                            'items' => ['type' => 'string', 'enum' => ['day', 'month', 'type', 'company_id', 'product', 'wallet', 'active', 'status', 'queue']],
                            'uniqueItems' => true,
                            'maxItems' => 3,
                        ],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
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

    /**
     * LinkedIn tool definitions when a provider is registered.
     *
     * @return list<array<string, mixed>>
     */
    public static function linkedInDefinitions(bool $includeWrite = true): array
    {
        $tools = [
            [
                'name' => 'linkedin_status',
                'description' => 'Returns LinkedIn OAuth connection status for a company (connected, member name, scopes, expiry). Call list_my_companies first to resolve company_id.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'company_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Company id from list_my_companies.'],
                    ],
                    'required' => ['company_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'linkedin_capabilities',
                'description' => 'Lists LinkedIn capabilities enabled by the current OAuth token scopes, and explicit limitations (e.g. profile field edit is not available via API).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'company_id' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['company_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'linkedin_get_profile',
                'description' => 'Reads OpenID/basic LinkedIn profile of the connected member (name, email, picture, optional /v2/me). Cannot update headline/about/experience via API — LinkedIn blocks third-party profile edits.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'company_id' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['company_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'linkedin_list_organizations',
                'description' => 'Lists LinkedIn company pages the member administers. Requires Community Management API + rw_organization_admin scope (reconnect OAuth after enabling the product).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'company_id' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['company_id'],
                    'additionalProperties' => false,
                ],
            ],
        ];

        if ($includeWrite) {
            $tools[] = [
                'name' => 'linkedin_create_post',
                'description' => 'Publishes a public text post on the connected LinkedIn member profile (w_member_social).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'company_id' => ['type' => 'integer', 'minimum' => 1],
                        'text' => ['type' => 'string', 'minLength' => 1],
                    ],
                    'required' => ['company_id', 'text'],
                    'additionalProperties' => false,
                ],
            ];
            $tools[] = [
                'name' => 'linkedin_create_organization_post',
                'description' => 'Publishes a public text post as a LinkedIn company page. Requires w_organization_social, page admin role, and organization_urn from linkedin_list_organizations.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'company_id' => ['type' => 'integer', 'minimum' => 1],
                        'organization_urn' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'description' => 'urn:li:organization:{id} or numeric organization id',
                        ],
                        'text' => ['type' => 'string', 'minLength' => 1],
                    ],
                    'required' => ['company_id', 'organization_urn', 'text'],
                    'additionalProperties' => false,
                ],
            ];
        }

        return $tools;
    }
    public function call(string $name, array $arguments): array
    {
        return match ($name) {
            'list_query_datasets' => $this->listDatasets(),
            'query_business_data' => $this->queryBusinessData($arguments),
            'write_business_data' => $this->writeBusinessData($arguments),
            'linkedin_status' => $this->linkedinStatus($arguments),
            'linkedin_create_post' => $this->linkedinCreatePost($arguments),
            'linkedin_capabilities' => $this->linkedinCapabilities($arguments),
            'linkedin_get_profile' => $this->linkedinGetProfile($arguments),
            'linkedin_list_organizations' => $this->linkedinListOrganizations($arguments),
            'linkedin_create_organization_post' => $this->linkedinCreateOrganizationPost($arguments),
            'mercadolivre_status' => $this->mercadoLivreStatus($arguments),
            'mercadolivre_get_current_user' => $this->mercadoLivreSimpleCall($arguments, fn (int $id): array => $this->mercadoLivre()->getCurrentUser($id)),
            'mercadolivre_search_items' => $this->mercadoLivreSearchItems($arguments),
            'mercadolivre_get_item' => $this->mercadoLivreResource($arguments, 'item_id', fn (int $id, string $key): array => $this->mercadoLivre()->getItem($id, $key)),
            'mercadolivre_get_item_description' => $this->mercadoLivreResource($arguments, 'item_id', fn (int $id, string $key): array => $this->mercadoLivre()->getItemDescription($id, $key)),
            'mercadolivre_get_category' => $this->mercadoLivreResource($arguments, 'category_id', fn (int $id, string $key): array => $this->mercadoLivre()->getCategory($id, $key)),
            'mercadolivre_get_order' => $this->mercadoLivreResource($arguments, 'order_id', fn (int $id, string $key): array => $this->mercadoLivre()->getOrder($id, $key)),
            'mercadolivre_import_products' => $this->mercadoLivreImportProducts($arguments),
            default => throw new \InvalidArgumentException('Unknown business data tool'),
        };
    }

    /** @param array<string, mixed> $arguments
     *  @return array<string, mixed>
     */
    private function mercadoLivre(): McpMercadoLivreProviderInterface
    {
        return $this->mercadoLivreProvider ?? throw new \RuntimeException('Mercado Livre tools are not configured');
    }

    private function mercadoLivreCompanyId(array $arguments): int
    {
        $id = $arguments['company_id'] ?? null;
        if (!is_int($id) || $id < 1) {
            throw new \InvalidArgumentException('company_id is required');
        }
        return $id;
    }

    private function mercadoLivreStatus(array $arguments): array
    {
        return $this->mercadoLivre()->getStatus($this->mercadoLivreCompanyId($arguments));
    }

    private function mercadoLivreSimpleCall(array $arguments, callable $callback): array
    {
        return $callback($this->mercadoLivreCompanyId($arguments));
    }

    private function mercadoLivreResource(array $arguments, string $field, callable $callback): array
    {
        $id = $arguments[$field] ?? null;
        if (!is_string($id) || trim($id) === '' || strlen($id) > 128) {
            throw new \InvalidArgumentException($field . ' is required');
        }
        return $callback($this->mercadoLivreCompanyId($arguments), trim($id));
    }

    private function mercadoLivreSearchItems(array $arguments): array
    {
        $offset = $arguments['offset'] ?? 0;
        $limit = $arguments['limit'] ?? 50;
        if (!is_int($offset) || $offset < 0 || !is_int($limit) || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('offset/limit are invalid');
        }
        return $this->mercadoLivre()->searchItems($this->mercadoLivreCompanyId($arguments), $offset, $limit);
    }

    private function mercadoLivreImportProducts(array $arguments): array
    {
        $showcaseId = $arguments['showcase_id'] ?? null;
        $limit = $arguments['limit'] ?? 50;
        if (!is_int($showcaseId) || $showcaseId < 1 || !is_int($limit) || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('showcase_id and limit are invalid');
        }
        return $this->mercadoLivre()->importProducts($this->mercadoLivreCompanyId($arguments), $showcaseId, $limit);
    }

    private function linkedinStatus(array $arguments): array
    {
        if ($this->linkedInProvider === null) {
            throw new \RuntimeException('LinkedIn tools are not configured');
        }
        $companyId = $arguments['company_id'] ?? null;
        if (!is_int($companyId) || $companyId < 1) {
            throw new \InvalidArgumentException('company_id is required');
        }

        return $this->linkedInProvider->getStatus($companyId);
    }

    /** @param array<string, mixed> $arguments
     *  @return array<string, mixed>
     */
    private function linkedinCreatePost(array $arguments): array
    {
        if ($this->linkedInProvider === null) {
            throw new \RuntimeException('LinkedIn tools are not configured');
        }
        $companyId = $arguments['company_id'] ?? null;
        $text = $arguments['text'] ?? null;
        if (!is_int($companyId) || $companyId < 1) {
            throw new \InvalidArgumentException('company_id is required');
        }
        if (!is_string($text) || trim($text) === '') {
            throw new \InvalidArgumentException('text is required');
        }

        return $this->linkedInProvider->createPost($companyId, trim($text));
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

        $rows = $this->provider()->query($dataset, [
            'from' => $arguments['from'] ?? null,
            'to' => $arguments['to'] ?? null,
            'company_id' => $companyId,
            'company_role' => $companyRole,
            'aggregate' => $aggregate,
            'group_by' => $groupBy,
            'limit' => $limit,
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

        return ['dataset' => $dataset, 'count' => count($rows), 'rows' => $rows] + ($rows === []
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
    /** @param array<string, mixed> $arguments
     *  @return array<string, mixed>
     */
    private function linkedinCapabilities(array $arguments): array
    {
        return $this->linkedInProvider()->getCapabilities($this->requireCompanyId($arguments));
    }

    /** @param array<string, mixed> $arguments
     *  @return array<string, mixed>
     */
    private function linkedinGetProfile(array $arguments): array
    {
        return $this->linkedInProvider()->getProfile($this->requireCompanyId($arguments));
    }

    /** @param array<string, mixed> $arguments
     *  @return array<string, mixed>
     */
    private function linkedinListOrganizations(array $arguments): array
    {
        return $this->linkedInProvider()->listOrganizations($this->requireCompanyId($arguments));
    }

    /** @param array<string, mixed> $arguments
     *  @return array<string, mixed>
     */
    private function linkedinCreateOrganizationPost(array $arguments): array
    {
        $companyId = $this->requireCompanyId($arguments);
        $organizationUrn = $arguments['organization_urn'] ?? null;
        $text = $arguments['text'] ?? null;
        if (!is_string($organizationUrn) || trim($organizationUrn) === '') {
            throw new \InvalidArgumentException('organization_urn is required');
        }
        if (!is_string($text) || trim($text) === '') {
            throw new \InvalidArgumentException('text is required');
        }

        return $this->linkedInProvider()->createOrganizationPost($companyId, trim($organizationUrn), trim($text));
    }

    /** @param array<string, mixed> $arguments */
    private function requireCompanyId(array $arguments): int
    {
        $companyId = $arguments['company_id'] ?? null;
        if (!is_int($companyId) || $companyId < 1) {
            throw new \InvalidArgumentException('company_id is required');
        }

        return $companyId;
    }

    private function linkedInProvider(): McpLinkedInProviderInterface
    {
        if ($this->linkedInProvider === null) {
            throw new \RuntimeException('LinkedIn tools are not configured');
        }

        return $this->linkedInProvider;
    }

}
