<?php

declare(strict_types=1);

namespace ControleOnline\Service;

/**
 * Optional LinkedIn integration for MCP tools.
 * Implemented by the integration module when LinkedIn OAuth is available.
 */
interface McpLinkedInProviderInterface
{
    /** @return array<string, mixed> */
    public function getStatus(int $companyId): array;

    /** @return array<string, mixed> */
    public function createPost(int $companyId, string $text): array;

    /** @return array<string, mixed> */
    public function getProfile(int $companyId): array;

    /** @return array<string, mixed> */
    public function listOrganizations(int $companyId): array;

    /** @return array<string, mixed> */
    public function createOrganizationPost(int $companyId, string $organizationUrn, string $text): array;

    /** @return array<string, mixed> */
    public function getCapabilities(int $companyId): array;
}
