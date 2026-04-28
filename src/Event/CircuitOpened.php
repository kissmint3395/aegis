<?php

declare(strict_types=1);

namespace Aegis\Event;

final readonly class CircuitOpened
{
    public function __construct(
        public string     $name,
        public \Throwable $cause,
    ) {}
}
