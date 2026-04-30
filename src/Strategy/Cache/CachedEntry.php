<?php

declare(strict_types=1);

namespace Aegis\Strategy\Cache;

final readonly class CachedEntry
{
    public function __construct(
        public mixed $value,
        public int   $freshUntilMs,
    ) {}

    public function isFresh(int $nowMs): bool
    {
        return $nowMs <= $this->freshUntilMs;
    }
}
