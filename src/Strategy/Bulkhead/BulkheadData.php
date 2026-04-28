<?php

declare(strict_types=1);

namespace Aegis\Strategy\Bulkhead;

final readonly class BulkheadData
{
    public function __construct(
        public int $concurrent = 0,
    ) {}

    public function withConcurrent(int $concurrent): self
    {
        return new self($concurrent);
    }
}
