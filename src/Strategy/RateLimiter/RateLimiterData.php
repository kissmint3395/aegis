<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter;

final readonly class RateLimiterData
{
    public function __construct(
        public int                  $count = 0,
        public ?\DateTimeImmutable  $windowStart = null,
    ) {}

    public function withCount(int $count): self
    {
        return new self($count, $this->windowStart);
    }
}
