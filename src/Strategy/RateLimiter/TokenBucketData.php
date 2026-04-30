<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter;

final readonly class TokenBucketData
{
    public function __construct(
        public float $tokens,
        public int   $lastRefillMs,
    ) {}
}
