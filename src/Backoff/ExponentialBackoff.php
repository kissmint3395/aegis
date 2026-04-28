<?php

declare(strict_types=1);

namespace Aegis\Backoff;

use Aegis\Contract\BackoffInterface;
use Aegis\Duration;

final readonly class ExponentialBackoff implements BackoffInterface
{
    private function __construct(
        private Duration  $baseDelay,
        private float     $multiplier,
        private ?Duration $maxDelay,
        private bool      $jitter,
    ) {}

    public static function create(
        Duration  $baseDelay,
        float     $multiplier = 2.0,
        ?Duration $maxDelay = null,
    ): self {
        return new self($baseDelay, $multiplier, $maxDelay, false);
    }

    public static function withJitter(
        Duration  $baseDelay,
        float     $multiplier = 2.0,
        ?Duration $maxDelay = null,
    ): self {
        return new self($baseDelay, $multiplier, $maxDelay, true);
    }

    public function delay(int $attempt): Duration
    {
        $ms = (int) ($this->baseDelay->toMilliseconds() * ($this->multiplier ** ($attempt - 1)));

        if ($this->maxDelay !== null) {
            $ms = min($ms, $this->maxDelay->toMilliseconds());
        }

        if ($this->jitter) {
            $ms = random_int((int) ($ms / 2), max(1, $ms));
        }

        return Duration::milliseconds($ms);
    }
}
