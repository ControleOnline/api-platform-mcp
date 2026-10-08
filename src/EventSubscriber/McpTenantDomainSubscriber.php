<?php

declare(strict_types=1);

namespace ControleOnline\EventSubscriber;

use ControleOnline\Service\DomainService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Selects the tenant from the MCP URL before multi-tenancy switches databases. */
final class McpTenantDomainSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly DomainService $domainService)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // DatabaseSwitchListener runs at priority 512, before routing at 32.
        return [KernelEvents::REQUEST => ['applyTenantDomain', 600]];
    }

    public function applyTenantDomain(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (preg_match('#^/mcp(?:/([A-Za-z0-9.-]+))?/?$#', $request->getPathInfo(), $matches) !== 1) {
            return;
        }

        $tenantDomain = $matches[1] ?? '';
        $domain = trim($tenantDomain) !== '' ? trim($tenantDomain) : $this->domainService->getMainDomain();
        if ($domain === '') {
            return;
        }

        // The MCP URL is authoritative; ignore client-provided tenant headers.
        $request->headers->set('app-domain', $domain);
        $request->attributes->set('app-domain', $domain);
    }
}
