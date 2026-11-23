<?php

declare(strict_types=1);

namespace ControleOnline\Service;

/**
 * Contract between the MCP marketing tools and the concrete HTTP calls to
 * api-platform-integration. Implemented by the hosting app so this module
 * stays transport-free.
 *
 * All methods receive the tenant company id resolved by the MCP server and
 * return the already-decoded JSON payload. Mutations throw when they would
 * exceed guardrails (budget swing > +50% without force flag).
 */
interface McpMarketingProviderInterface
{
    /** @return array<string, mixed> */
    public function connectStatus(int $companyId): array;

    /** @return array<string, mixed> */
    public function ga4Report(int $companyId, array $filters): array;

    /** @return array<string, mixed> */
    public function adsCampaigns(int $companyId): array;

    /** @return array<string, mixed> */
    public function adsReport(int $companyId, array $filters): array;

    /** @return array<string, mixed> */
    public function adsPause(int $companyId, string $campaignId): array;

    /** @return array<string, mixed> */
    public function adsResume(int $companyId, string $campaignId): array;

    /** @return array<string, mixed> */
    public function adsUpdateBudget(int $companyId, string $campaignId, int $amountMicros, bool $force): array;

    /** @return array<string, mixed> */
    public function adsenseReport(int $companyId, array $filters): array;

    /** @return array<string, mixed> */
    public function searchConsoleReport(int $companyId, array $filters): array;
}
