<?php

declare(strict_types=1);

namespace Aegis\Strategy\Bulkhead;

final readonly class BulkheadOptions
{
    /**
     * @param string $name          Unique identifier (used as storage key).
     * @param int    $maxConcurrent Maximum number of concurrent executions allowed.
     */
    public function __construct(
        public string $name,
        public int    $maxConcurrent = 10,
    ) {
        if ($maxConcurrent < 1) {
            throw new \InvalidArgumentException('maxConcurrent must be >= 1.');
        }
    }
}
