<?php

declare(strict_types=1);

namespace Aegis\Event;

final readonly class RetryAttempted
{
    public function __construct(
        public int        $attempt,
        public int        $maxAttempts,
        public \Throwable $cause,
        public int        $delayMs,
    ) {}
}
