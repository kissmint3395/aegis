<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter;

final readonly class SlidingWindowData
{
    /**
     * @param list<int> $timestamps Unix timestamps in milliseconds
     */
    public function __construct(
        public array $timestamps = [],
    ) {}

    public function evictExpired(int $windowMs, int $nowMs): self
    {
        $cutoff = $nowMs - $windowMs;
        $valid  = array_values(array_filter($this->timestamps, fn (int $ts) => $ts > $cutoff));
        return new self($valid);
    }

    public function withAppended(int $timestampMs): self
    {
        return new self([...$this->timestamps, $timestampMs]);
    }

    public function count(): int
    {
        return count($this->timestamps);
    }
}
