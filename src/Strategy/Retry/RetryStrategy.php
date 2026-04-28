<?php

declare(strict_types=1);

namespace Aegis\Strategy\Retry;

use Psr\EventDispatcher\EventDispatcherInterface;
use Aegis\Contract\StrategyInterface;
use Aegis\Event\RetryAttempted;
use Aegis\Exception\RetryExhaustedException;

final class RetryStrategy implements StrategyInterface
{
    public function __construct(
        private readonly RetryOptions             $options,
        private readonly EventDispatcherInterface $dispatcher,
    ) {}

    public function execute(callable $next): mixed
    {
        $attempt       = 0;
        $lastException = null;

        while ($attempt < $this->options->maxAttempts) {
            $attempt++;
            try {
                return $next();
            } catch (\Throwable $e) {
                if (!$this->shouldRetry($e)) {
                    throw $e;
                }

                $lastException = $e;

                if ($attempt < $this->options->maxAttempts) {
                    $delay = $this->options->backoff->delay($attempt);
                    $this->dispatcher->dispatch(new RetryAttempted($attempt, $this->options->maxAttempts, $e, $delay->toMilliseconds()));

                    if ($delay->toMicroseconds() > 0) {
                        usleep($delay->toMicroseconds());
                    }
                }
            }
        }

        assert($lastException !== null);
        throw new RetryExhaustedException($attempt, $lastException);
    }

    private function shouldRetry(\Throwable $e): bool
    {
        $matchesClass = false;
        foreach ($this->options->retryOn as $class) {
            if ($e instanceof $class) {
                $matchesClass = true;
                break;
            }
        }

        if (!$matchesClass) {
            return false;
        }

        if ($this->options->retryIf !== null) {
            return ($this->options->retryIf)($e);
        }

        return true;
    }
}
