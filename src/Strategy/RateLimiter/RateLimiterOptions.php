<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter;

use Aegis\Duration;

final readonly class RateLimiterOptions
{
    public Duration $window;

    /**
     * @param string       $name   Unique identifier (used as storage key).
     * @param int          $limit  Maximum number of calls allowed per window.
     * @param Duration|null $window Time window length. Defaults to 60 seconds.
     */
    public function __construct(
        public string  $name,
        public int     $limit = 100,
        ?Duration      $window = null,
    ) {
        if ($limit < 1) {
            throw new \InvalidArgumentException('limit must be >= 1.');
        }
        $this->window = $window ?? Duration::seconds(60);
    }
}
