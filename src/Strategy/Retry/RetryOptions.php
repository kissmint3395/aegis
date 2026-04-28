<?php

declare(strict_types=1);

namespace Aegis\Strategy\Retry;

use Aegis\Backoff\FixedBackoff;
use Aegis\Contract\BackoffInterface;
use Aegis\Duration;

final readonly class RetryOptions
{
    public BackoffInterface $backoff;

    /**
     * @param int                             $maxAttempts Max total attempts (first call + retries).
     * @param BackoffInterface|null           $backoff     Delay strategy; defaults to no delay.
     * @param array<class-string<\Throwable>> $retryOn     Exception classes that trigger a retry.
     * @param (callable(\Throwable): bool)|null $retryIf   Additional predicate; retries only when true.
     */
    public function __construct(
        public int              $maxAttempts = 3,
        ?BackoffInterface       $backoff = null,
        public array            $retryOn = [\Throwable::class],
        public mixed            $retryIf = null,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be >= 1.');
        }
        $this->backoff = $backoff ?? new FixedBackoff(Duration::zero());
    }
}
