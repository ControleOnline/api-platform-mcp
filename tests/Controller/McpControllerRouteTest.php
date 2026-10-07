<?php

declare(strict_types=1);

namespace ControleOnline\Mcp\Tests\Controller;

use ControleOnline\Controller\McpController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

final class McpControllerRouteTest extends TestCase
{
    public function testEndpointSupportsDefaultAndExplicitTenantPaths(): void
    {
        $method = new \ReflectionMethod(McpController::class, '__invoke');
        $routes = $method->getAttributes(Route::class);

        self::assertSame(2, count($routes));
        self::assertSame('', $routes[0]->getArguments()[0]);
        self::assertSame('/{tenantDomain}', $routes[1]->getArguments()[0]);
        self::assertSame(['GET', 'POST', 'OPTIONS'], $routes[0]->getArguments()['methods']);
        self::assertSame('[A-Za-z0-9.-]+', $routes[1]->getArguments()['requirements']['tenantDomain']);
        self::assertSame(null, (new \ReflectionParameter([$method->class, $method->name], 'tenantDomain'))->getDefaultValue());
    }
}
