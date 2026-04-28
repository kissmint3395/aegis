<?php

declare(strict_types=1);

namespace Aegis\Event;

final readonly class CircuitClosed
{
    public function __construct(
        public string $name,
    ) {}
}
