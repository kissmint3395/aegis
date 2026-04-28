<?php

declare(strict_types=1);

namespace Aegis\Backoff;

use Aegis\Contract\BackoffInterface;
use Aegis\Duration;

final readonly class FixedBackoff implements BackoffInterface
{
    public function __construct(
        private Duration $delay,
    ) {}

    public function delay(int $attempt): Duration
    {
        return $this->delay;
    }
}
