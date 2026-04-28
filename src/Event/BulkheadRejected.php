<?php

declare(strict_types=1);

namespace Aegis\Event;

final readonly class BulkheadRejected
{
    public function __construct(
        public string $name,
        public int    $maxConcurrent,
    ) {}
}
