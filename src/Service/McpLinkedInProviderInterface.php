<?php

declare(strict_types=1);

namespace ControleOnline\Service;

/**
 * Optional LinkedIn integration for MCP tools.
 * Implemented by the integration module when LinkedIn OAuth is available.
 */
interface McpLinkedInProviderInterface
{
    /**
     * Connection status for a company the current user can access.
     *
     * @return array<string, mixed>
     */
    public function getStatus(int $companyId): array;

    /**
     * Publish a personal member text post for the connected company account.
     *
     * @return array<string, mixed>
     */
    public function createPost(int $companyId, string $text): array;
}
