<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter;

use Psr\EventDispatcher\EventDispatcherInterface;
use Aegis\Contract\StrategyInterface;
use Aegis\Event\RateLimitExceeded;
use Aegis\Exception\RateLimitExceededException;
use Aegis\Strategy\RateLimiter\Storage\SlidingWindowStorageInterface;

final class SlidingWindowRateLimiterStrategy implements StrategyInterface
{
    public function __construct(
        private readonly RateLimiterOptions          $options,
        private readonly SlidingWindowStorageInterface $storage,
        private readonly EventDispatcherInterface    $dispatcher,
    ) {}

    public function execute(callable $next): mixed
    {
        $nowMs  = (int)(microtime(true) * 1000);
        $data   = $this->storage->get($this->options->name);

        $data = $data->evictExpired($this->options->window->toMilliseconds(), $nowMs);

        if ($data->count() >= $this->options->limit) {
            $this->dispatcher->dispatch(new RateLimitExceeded($this->options->name, $this->options->limit));
            throw new RateLimitExceededException($this->options->name, $this->options->limit);
        }

        $this->storage->save($this->options->name, $data->withAppended($nowMs));

        return $next();
    }
}
