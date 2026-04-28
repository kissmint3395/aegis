<?php

declare(strict_types=1);

namespace Aegis\Strategy\Timeout;

use Aegis\Contract\StrategyInterface;
use Aegis\Duration;
use Aegis\Exception\TimeoutExceededException;

/**
 * Enforces a maximum execution duration.
 *
 * On Unix with pcntl extension: uses SIGALRM for preemptive interruption.
 * On Windows / without pcntl: measures elapsed time after execution and throws
 * if the deadline was exceeded (useful for limiting retry budgets).
 */
final class TimeoutStrategy implements StrategyInterface
{
    public function __construct(
        private readonly Duration $timeout,
    ) {}

    public function execute(callable $next): mixed
    {
        if ($this->isPcntlAvailable()) {
            return $this->executeWithSignal($next);
        }

        return $this->executeWithElapsedCheck($next);
    }

    private function executeWithSignal(callable $next): mixed
    {
        $seconds = (int) ceil($this->timeout->toSeconds());
        $timeout = $this->timeout;

        pcntl_signal(SIGALRM, static function () use ($timeout): never {
            throw new TimeoutExceededException($timeout);
        });

        pcntl_alarm($seconds);

        try {
            $result = $next();
            pcntl_alarm(0);
            return $result;
        } catch (TimeoutExceededException $e) {
            throw $e;
        } catch (\Throwable $e) {
            pcntl_alarm(0);
            throw $e;
        }
    }

    private function executeWithElapsedCheck(callable $next): mixed
    {
        $startMs = (int) (microtime(true) * 1000);
        $result  = $next();
        $elapsedMs = (int) (microtime(true) * 1000) - $startMs;

        if ($elapsedMs > $this->timeout->toMilliseconds()) {
            throw new TimeoutExceededException($this->timeout);
        }

        return $result;
    }

    private function isPcntlAvailable(): bool
    {
        return function_exists('pcntl_signal') && function_exists('pcntl_alarm');
    }
}
