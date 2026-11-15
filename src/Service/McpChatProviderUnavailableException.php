<?php

declare(strict_types=1);

namespace ControleOnline\Service;

/**
 * Transient AI provider failure (overload, rate limit, timeout) or a retired model:
 * McpChatLlmClient retries it and then moves to the next configured model.
 */
final class McpChatProviderUnavailableException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $modelGone = false,
        public readonly bool $timedOut = false,
    )
    {
        parent::__construct($message);
    }
}
