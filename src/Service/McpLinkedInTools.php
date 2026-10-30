<?php

declare(strict_types=1);

namespace ControleOnline\Service;

/** LinkedIn protocol helpers, kept separate from scoped business-query handling. */
trait McpLinkedInTools
{
    public function canLinkedIn(): bool
    {
        return $this->linkedInProvider !== null;
    }

    public function canLinkedInWrite(): bool
    {
        return $this->linkedInProvider !== null;
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

    /** @param array<string, mixed> $arguments
     *  @return array<string, mixed>
     */
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
