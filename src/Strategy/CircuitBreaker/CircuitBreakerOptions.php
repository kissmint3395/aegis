<?php

declare(strict_types=1);

namespace Aegis\Strategy\CircuitBreaker;

use Aegis\Duration;

final readonly class CircuitBreakerOptions
{
    public Duration $resetAfter;

    /**
     * @param string                          $name             Unique identifier (used as storage key).
     * @param int                             $failureThreshold Consecutive failures to open the circuit.
     * @param int                             $successThreshold Consecutive successes in HalfOpen to close.
     * @param Duration|null                   $resetAfter       Time to wait in Open state before probing.
     * @param array<class-string<\Throwable>> $ignoreExceptions Exceptions that do not count as failures.
     */
    public function __construct(
        public string   $name,
        public int      $failureThreshold = 5,
        public int      $successThreshold = 2,
        ?Duration       $resetAfter = null,
        public array    $ignoreExceptions = [],
    ) {
        $this->resetAfter = $resetAfter ?? Duration::seconds(30);
    }
}
