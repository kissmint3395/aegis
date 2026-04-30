<?php

declare(strict_types=1);

namespace Aegis\Strategy\CircuitBreaker;

use Aegis\Duration;

final readonly class AdaptiveCircuitBreakerOptions
{
    public Duration $resetAfter;

    /**
     * @param string                          $name                 Unique identifier.
     * @param int                             $windowSize           Number of recent calls to evaluate.
     * @param float                           $failureRateThreshold Failure rate percentage (0–100) to open the circuit.
     * @param int                             $minimumCalls         Minimum calls before the rate is evaluated.
     * @param int                             $successThreshold     Consecutive successes in HalfOpen to close.
     * @param Duration|null                   $resetAfter           Time in Open state before probing. Defaults to 30s.
     * @param array<class-string<\Throwable>> $ignoreExceptions     Exceptions that do not count as failures.
     */
    public function __construct(
        public string  $name,
        public int     $windowSize = 20,
        public float   $failureRateThreshold = 50.0,
        public int     $minimumCalls = 5,
        public int     $successThreshold = 2,
        ?Duration      $resetAfter = null,
        public array   $ignoreExceptions = [],
    ) {
        if ($failureRateThreshold <= 0.0 || $failureRateThreshold > 100.0) {
            throw new \InvalidArgumentException('failureRateThreshold must be in range (0, 100].');
        }
        $this->resetAfter = $resetAfter ?? Duration::seconds(30);
    }
}
