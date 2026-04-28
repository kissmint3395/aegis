<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter;

use Psr\EventDispatcher\EventDispatcherInterface;
use Aegis\Contract\StrategyInterface;
use Aegis\Event\RateLimitExceeded;
use Aegis\Exception\RateLimitExceededException;
use Aegis\Strategy\RateLimiter\Storage\StorageInterface;

final class RateLimiterStrategy implements StrategyInterface
{
    public function __construct(
        private readonly RateLimiterOptions       $options,
        private readonly StorageInterface         $storage,
        private readonly EventDispatcherInterface $dispatcher,
    ) {}

    public function execute(callable $next): mixed
    {
        $now  = new \DateTimeImmutable();
        $data = $this->storage->get($this->options->name);

        if ($data->windowStart === null || $this->isWindowExpired($data->windowStart, $now)) {
            $data = new RateLimiterData(0, $now);
        }

        if ($data->count >= $this->options->limit) {
            $this->dispatcher->dispatch(new RateLimitExceeded($this->options->name, $this->options->limit));
            throw new RateLimitExceededException($this->options->name, $this->options->limit);
        }

        $this->storage->save($this->options->name, $data->withCount($data->count + 1));

        return $next();
    }

    private function isWindowExpired(\DateTimeImmutable $windowStart, \DateTimeImmutable $now): bool
    {
        $elapsedMs = ($now->getTimestamp() - $windowStart->getTimestamp()) * 1000;
        return $elapsedMs >= $this->options->window->toMilliseconds();
    }
}
