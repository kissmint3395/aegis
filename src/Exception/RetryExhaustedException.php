<?php

declare(strict_types=1);

namespace Aegis\Exception;

final class RetryExhaustedException extends \RuntimeException
{
    public function __construct(int $attempts, \Throwable $lastException)
    {
        parent::__construct(
            sprintf('Retry exhausted after %d attempt(s): %s', $attempts, $lastException->getMessage()),
            previous: $lastException,
        );
    }
}
