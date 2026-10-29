<?php

declare(strict_types=1);

namespace ControleOnline\Service;

use Psr\Log\LoggerInterface;

final class McpMarketplaceTools
{
    /** @var array<string, McpMarketplaceProviderInterface> */
    private array $providersByTool = [];
    /** @var array<string, bool> */
    private array $writeTools = [];
    /** @var array<string, array<string, mixed>> */
    private array $definitionsByTool = [];

    /** @param iterable<McpMarketplaceProviderInterface> $providers */
    public function __construct(
        iterable $providers = [],
        private readonly ?McpMarketplaceAuthorizationProviderInterface $authorization = null,
        private readonly ?McpCompanyScopeProviderInterface $companyScopeProvider = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        foreach ($providers as $provider) {
            $marketplace = $provider->marketplace();
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $marketplace) !== 1) {
                throw new \LogicException('Marketplace providers must use a stable lowercase slug');
            }
            foreach ($provider->toolDefinitions() as $definition) {
                $name = $definition['name'] ?? null;
                $prefix = 'marketplace_' . str_replace('-', '_', $marketplace) . '_';
                if (!is_string($name) || !str_starts_with($name, $prefix)) {
                    throw new \LogicException('Marketplace tool names must be prefixed with their marketplace slug');
                }
                if (isset($this->providersByTool[$name])) {
                    throw new \LogicException('Duplicate marketplace tool name');
                }
                $this->providersByTool[$name] = $provider;
                $this->definitionsByTool[$name] = $definition;
                $this->writeTools[$name] = $provider->isWriteOperation($name);
            }
        }
    }

    public function isAvailable(): bool
    {
        return $this->providersByTool !== []
            && $this->authorization !== null
            && $this->companyScopeProvider !== null
            && $this->logger !== null;
    }

    public function supportsTool(string $toolName): bool
    {
        return $this->isAvailable() && isset($this->providersByTool[$toolName]);
    }

    public function isWriteOperation(string $toolName): bool
    {
        return $this->supportsTool($toolName) && ($this->writeTools[$toolName] ?? false);
    }

    /** @return list<array<string, mixed>> */
    public function definitions(bool $includeWrites = false): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $definitions = [];
        foreach ($this->definitionsByTool as $name => $definition) {
            if ($includeWrites || !($this->writeTools[$name] ?? false)) {
                $definitions[] = $definition;
            }
        }
        return $definitions;
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    public function call(string $toolName, array $arguments): array
    {
        if (!$this->supportsTool($toolName)) {
            throw new \InvalidArgumentException('Unknown marketplace tool');
        }
        $companyId = $arguments['company_id'] ?? null;
        if (!is_int($companyId) || $companyId < 1) {
            throw new \InvalidArgumentException('company_id is required');
        }
        $connectionId = $arguments['connection_id'] ?? null;
        if ($connectionId !== null && !is_int($connectionId) && !is_string($connectionId)) {
            throw new \InvalidArgumentException('connection_id must be a string or positive integer');
        }
        $resourceType = $arguments['resource_type'] ?? null;
        if ($resourceType !== null && !is_string($resourceType)) {
            throw new \InvalidArgumentException('resource_type must be a string');
        }
        $resourceId = $arguments['resource_id'] ?? null;
        if ($resourceId !== null && !is_int($resourceId) && !is_string($resourceId)) {
            throw new \InvalidArgumentException('resource_id must be a string or positive integer');
        }

        $provider = $this->providersByTool[$toolName];
        $context = new McpMarketplaceOperationContext(
            $this->authorization->currentUserId(),
            $companyId,
            $provider->marketplace(),
            $toolName,
            $connectionId,
            $resourceType,
            $resourceId,
        );
        $this->audit($context, 'started');

        try {
            $this->assertNoCredentialFields($arguments);
            $this->assertCompanyInCurrentScope($companyId);
            $this->authorization->assertAllowed($context);
            $result = $provider->execute($toolName, $context, $arguments, $this->authorization);
            $this->audit($context, 'success', $this->externalResourceId($result));
            return $result;
        } catch (\Throwable $exception) {
            $class = strtolower($exception::class);
            $outcome = $exception instanceof \InvalidArgumentException
                ? 'validation_error'
                : (str_contains($class, 'accessdenied') ? 'denied' : 'failure');
            $this->audit($context, $outcome, null, $exception::class);
            throw $exception;
        }
    }

    /** @param array<mixed> $value */
    private function assertNoCredentialFields(array $value): void
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/password|secret|token|credential|private.?key|authorization/i', $key) === 1) {
                throw new \InvalidArgumentException('Credential fields cannot be supplied to marketplace tools');
            }
            if (is_array($item)) {
                $this->assertNoCredentialFields($item);
            }
        }
    }

    private function assertCompanyInCurrentScope(int $companyId): void
    {
        foreach ($this->companyScopeProvider->listForCurrentUser() as $company) {
            if (is_array($company) && ($company['id'] ?? null) === $companyId) {
                return;
            }
        }
        throw new McpMarketplaceAccessDeniedException('Marketplace company access denied');
    }

    /** @param array<string, mixed> $result */
    private function externalResourceId(array $result): int|string|null
    {
        foreach (['external_resource_id', 'external_id'] as $key) {
            $value = $result[$key] ?? null;
            if (is_int($value) || (is_string($value) && trim($value) !== '')) {
                return $value;
            }
        }
        return null;
    }

    private function audit(
        McpMarketplaceOperationContext $context,
        string $outcome,
        int|string|null $externalResourceId = null,
        ?string $exceptionClass = null,
    ): void {
        $event = [
            'occurred_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'user_id' => $context->userId,
            'company_id' => $context->companyId,
            'marketplace' => $context->marketplace,
            'connection_id' => $context->connectionId,
            'operation' => $context->operation,
            'resource_type' => $context->resourceType,
            'resource_id' => $context->resourceId,
            'outcome' => $outcome,
        ];
        if ($externalResourceId !== null) {
            $event['external_resource_id'] = $externalResourceId;
        }
        if ($exceptionClass !== null) {
            $event['exception_class'] = $exceptionClass;
        }
        // Never log arguments, access tokens, refresh tokens, credentials, or exception messages.
        if (in_array($outcome, ['denied', 'failure', 'validation_error'], true)) {
            $this->logger?->warning('MCP marketplace operation', $event);
            return;
        }
        $this->logger?->info('MCP marketplace operation', $event);
    }
}
