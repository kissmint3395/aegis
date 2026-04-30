<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter;

use Aegis\Duration;

final readonly class TokenBucketOptions
{
    /**
     * @param string   $name         Unique identifier.
     * @param int      $capacity     Maximum number of tokens in the bucket.
     * @param int      $refillRate   Number of tokens added per refill period.
     * @param Duration $refillPeriod Duration of one refill period.
     */
    public function __construct(
        public string   $name,
        public int      $capacity,
        public int      $refillRate,
        public Duration $refillPeriod,
    ) {
        if ($capacity < 1) {
            throw new \InvalidArgumentException('capacity must be >= 1.');
        }
        if ($refillRate < 1) {
            throw new \InvalidArgumentException('refillRate must be >= 1.');
        }
    }
}
