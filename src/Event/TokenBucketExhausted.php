<?php

declare(strict_types=1);

namespace Aegis\Event;

final readonly class TokenBucketExhausted
{
    public function __construct(
        public string $name,
        public int    $capacity,
    ) {}
}
