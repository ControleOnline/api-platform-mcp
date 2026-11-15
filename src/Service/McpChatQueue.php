<?php

declare(strict_types=1);

namespace ControleOnline\Service;

use ControleOnline\Entity\People;
use ControleOnline\Entity\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Queues an McpChat job and runs it right after the HTTP response is sent (kernel.terminate),
 * so /ai does not wait for the worker. The integration row stays as the fallback: if the request
 * process dies, the Messenger worker processes it; executeIntegration's lock prevents running twice.
 */
class McpChatQueue
{
    public function __construct(
        private readonly IntegrationService $integrationService,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /** @param array<string, mixed> $job */
    public function add(array $job, People $company, ?User $user): int
    {
        $integration = $this->integrationService->addIntegration(
            (string) json_encode($job, JSON_UNESCAPED_UNICODE),
            'McpChat',
            null,
            $user,
            $company,
        );

        $this->dispatcher?->addListener(KernelEvents::TERMINATE, function () use ($integration): void {
            try {
                $this->integrationService->executeIntegration($integration);
            } catch (\Throwable) {
                // The worker retries it.
            }
        });

        return (int) $integration->getId();
    }
}
