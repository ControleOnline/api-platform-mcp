<?php

declare(strict_types=1);

namespace ControleOnline\Mcp\Tests\Service;

use PHPUnit\Framework\TestCase;

final class McpServiceWiringTest extends TestCase
{
    public function testBusinessToolsAreRegisteredForDependencyInjection(): void
    {
        $services = file_get_contents(dirname(__DIR__, 2) . '/config/services/services.yaml');

        self::assertIsString($services);
        self::assertStringContainsString('ControleOnline\\Service\\McpBusinessTools:', $services);
    }
}
