<?php

declare(strict_types=1);

namespace Aegis\Strategy\Hedge;

use Psr\EventDispatcher\EventDispatcherInterface;
use Aegis\Contract\StrategyInterface;
use Aegis\Exception\HedgeExhaustedException;

/**
 * Experimental: fires additional "hedge" requests after a delay and returns
 * the first successful result.
 *
 * True latency-based hedging requires callables that use Fiber::suspend() for
 * I/O waits (e.g. ReactPHP / Swoole). For synchronous callables, hedges fire
 * immediately after each failure.
 */
final class HedgeStrategy implements StrategyInterface
{
    public function __construct(
        private readonly HedgeOptions             $options,
        private readonly EventDispatcherInterface $dispatcher,
    ) {}

    public function execute(callable $next): mixed
    {
        $startMs     = (int)(microtime(true) * 1000);
        $delayMs     = $this->options->delay->toMilliseconds();
        $maxHedges   = $this->options->maxHedges;
        $firstResult = null;
        $hasResult   = false;
        /** @var array<int, \Throwable> */
        $throwables  = [];
        $hedgesFired = 0;

        /** @var list<\Fiber<void, void, void, void>> */
        $fibers = [];

        $spawnFiber = function () use ($next, &$fibers, &$firstResult, &$hasResult, &$throwables): void {
            $idx   = count($fibers);
            $fiber = new \Fiber(static function () use ($next, $idx, &$firstResult, &$hasResult, &$throwables): void {
                try {
                    $result = $next();
                    if (!$hasResult) {
                        $firstResult = $result;
                        $hasResult   = true;
                    }
                } catch (\Throwable $e) {
                    $throwables[$idx] = $e;
                }
            });
            $fibers[] = $fiber;
            $fiber->start();
        };

        $spawnFiber(); // primary attempt

        if ($hasResult) {
            return $firstResult;
        }

        while (true) {
            $nowMs     = (int)(microtime(true) * 1000);
            $suspended = array_filter($fibers, static fn(\Fiber $f): bool => $f->isSuspended());

            // Fire a hedge when: delay elapsed, OR no suspended fibers + failure detected (sync mode)
            $syncFailMode = empty($suspended) && !$hasResult && !empty($throwables);
            if ($hedgesFired < $maxHedges && (($nowMs - $startMs) >= $delayMs || $syncFailMode)) {
                $spawnFiber();
                $hedgesFired++;
                if ($hasResult) {
                    break;
                }
                continue; // re-evaluate after spawning
            }

            // Resume all suspended fibers
            foreach ($suspended as $fiber) {
                $fiber->resume();
                if ($hasResult) {
                    break 2;
                }
            }

            // Determine whether to continue
            $stillSuspended = array_filter($fibers, static fn(\Fiber $f): bool => $f->isSuspended());
            if (empty($stillSuspended) && $hedgesFired >= $maxHedges) {
                break;
            }
        }

        if ($hasResult) {
            return $firstResult;
        }

        throw new HedgeExhaustedException(array_values($throwables));
    }
}
