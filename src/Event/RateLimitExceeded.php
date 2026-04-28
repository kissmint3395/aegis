<?php

declare(strict_types=1);

namespace Aegis\Event;

final readonly class RateLimitExceeded
{
    public function __construct(
        public string $name,
        public int    $limit,
    ) {}
}
