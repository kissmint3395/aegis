<?php

declare(strict_types=1);

namespace Aegis\Strategy\Hedge;

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
        private readonly HedgeOptions $options,
    ) {}

    public function execute(callable $next): mixed
    {
        $startMs     = (int)(microtime(true) * 1000);
        $delayMs     = $this->options->delay->toMilliseconds();
        $maxHedges   = $this->options->maxHedges;
        $hedgesFired = 0;

        /** @var list<\Fiber<mixed, void, mixed, void>> */
        $fibers     = [];
        /** @var list<\Throwable> */
        $throwables = [];

        // Returns the fiber when it completed synchronously; null when suspended or threw.
        $startFiber = function () use ($next, &$fibers, &$throwables): ?\Fiber {
            /** @var \Fiber<mixed, void, mixed, void> */
            $fiber = new \Fiber($next);
            $fibers[] = $fiber;
            try {
                $fiber->start();
                if ($fiber->isTerminated()) {
                    return $fiber;
                }
            } catch (\Throwable $e) {
                $throwables[] = $e;
            }
            return null;
        };

        $done = $startFiber(); // primary
        if ($done !== null) {
            return $done->getReturn();
        }

        while (true) {
            $nowMs     = (int)(microtime(true) * 1000);
            $suspended = array_filter($fibers, static fn(\Fiber $f): bool => $f->isSuspended());
            $elapsed   = ($nowMs - $startMs) >= $delayMs;
            $syncFail  = $suspended === [];

            if ($hedgesFired < $maxHedges && ($elapsed || $syncFail)) {
                $done = $startFiber();
                $hedgesFired++;
                if ($done !== null) {
                    return $done->getReturn();
                }
                continue;
            }

            foreach ($suspended as $fiber) {
                try {
                    $fiber->resume();
                    if ($fiber->isTerminated()) {
                        return $fiber->getReturn();
                    }
                } catch (\Throwable $e) {
                    $throwables[] = $e;
                }
            }

            $stillSuspended = array_filter($fibers, static fn(\Fiber $f): bool => $f->isSuspended());
            if ($stillSuspended === [] && $hedgesFired >= $maxHedges) {
                break;
            }
        }

        throw new HedgeExhaustedException($throwables);
    }
}
