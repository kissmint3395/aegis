<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter;

use Psr\EventDispatcher\EventDispatcherInterface;
use Aegis\Contract\StrategyInterface;
use Aegis\Event\TokenBucketExhausted;
use Aegis\Exception\TokenBucketExhaustedException;
use Aegis\Strategy\RateLimiter\Storage\TokenBucketStorageInterface;

final class TokenBucketRateLimiterStrategy implements StrategyInterface
{
    public function __construct(
        private readonly TokenBucketOptions          $options,
        private readonly TokenBucketStorageInterface $storage,
        private readonly EventDispatcherInterface    $dispatcher,
    ) {}

    public function execute(callable $next): mixed
    {
        $nowMs = (int)(microtime(true) * 1000);
        $data  = $this->storage->get($this->options->name)
            ?? new TokenBucketData((float)$this->options->capacity, $nowMs);

        $tokens = $this->refill($data, $nowMs);

        if ($tokens < 1.0) {
            $this->dispatcher->dispatch(new TokenBucketExhausted($this->options->name, $this->options->capacity));
            throw new TokenBucketExhaustedException($this->options->name, $this->options->capacity);
        }

        $this->storage->save($this->options->name, new TokenBucketData($tokens - 1.0, $nowMs));

        return $next();
    }

    private function refill(TokenBucketData $data, int $nowMs): float
    {
        $elapsedMs   = $nowMs - $data->lastRefillMs;
        $periodMs    = $this->options->refillPeriod->toMilliseconds();
        $ratePerMs   = $this->options->refillRate / $periodMs;
        $tokensToAdd = $elapsedMs * $ratePerMs;

        return min((float)$this->options->capacity, $data->tokens + $tokensToAdd);
    }
}
