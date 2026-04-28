<?php

declare(strict_types=1);

namespace Aegis\Event;

final readonly class CircuitHalfOpened
{
    public function __construct(
        public string $name,
    ) {}
}
