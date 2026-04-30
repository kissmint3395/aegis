<?php

declare(strict_types=1);

namespace Aegis\Strategy\Hedge;

use Aegis\Duration;

final readonly class HedgeOptions
{
    public function __construct(
        public Duration $delay,
        public int      $maxHedges = 1,
    ) {
        if ($maxHedges < 0) {
            throw new \InvalidArgumentException('maxHedges must be >= 0.');
        }
    }
}
