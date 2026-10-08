<?php

declare(strict_types=1);

namespace ControleOnline\Mcp\Tests\EventSubscriber;

use ControleOnline\EventSubscriber\McpTenantDomainSubscriber;
use ControleOnline\Service\DomainService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class McpTenantDomainSubscriberTest extends TestCase
{
    public function testTenantPathOverridesClientDomainBeforeDatabaseSwitch(): void
    {
        $request = Request::create('https://api.controleonline.com/mcp/app.controleonline.com');
        $request->headers->set('app-domain', 'attacker.example');
        $domain = $this->createMock(DomainService::class);
        $domain->expects(self::never())->method('getMainDomain');

        $subscriber = new McpTenantDomainSubscriber($domain);
        self::assertSame(600, $subscriber::getSubscribedEvents()[KernelEvents::REQUEST][1]);
        $subscriber->applyTenantDomain($this->event($request));

        self::assertSame('app.controleonline.com', $request->headers->get('app-domain'));
        self::assertSame('app.controleonline.com', $request->attributes->get('app-domain'));
    }

    public function testBareMcpPathUsesMainDomain(): void
    {
        $request = Request::create('https://api.controleonline.com/mcp');
        $request->headers->set('app-domain', 'attacker.example');
        $domain = $this->createMock(DomainService::class);
        $domain->expects(self::once())->method('getMainDomain')->willReturn('api.controleonline.com');

        (new McpTenantDomainSubscriber($domain))->applyTenantDomain($this->event($request));

        self::assertSame('api.controleonline.com', $request->headers->get('app-domain'));
    }

    public function testDoesNotChangeNonMcpOrSubRequests(): void
    {
        $domain = $this->createMock(DomainService::class);
        $domain->expects(self::never())->method('getMainDomain');
        $subscriber = new McpTenantDomainSubscriber($domain);

        $request = Request::create('https://api.controleonline.com/');
        $request->headers->set('app-domain', 'unchanged.example');
        $subscriber->applyTenantDomain($this->event($request));
        self::assertSame('unchanged.example', $request->headers->get('app-domain'));

        $subRequest = Request::create('https://api.controleonline.com/mcp/shop.example');
        $subscriber->applyTenantDomain($this->event($subRequest, HttpKernelInterface::SUB_REQUEST));
        self::assertNull($subRequest->headers->get('app-domain'));
    }

    private function event(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $type);
    }
}
