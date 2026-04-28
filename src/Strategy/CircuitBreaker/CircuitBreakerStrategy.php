<?php

declare(strict_types=1);

namespace Aegis\Strategy\CircuitBreaker;

use Psr\EventDispatcher\EventDispatcherInterface;
use Aegis\Contract\StrategyInterface;
use Aegis\Event\CircuitClosed;
use Aegis\Event\CircuitHalfOpened;
use Aegis\Event\CircuitOpened;
use Aegis\Exception\CircuitOpenException;
use Aegis\Strategy\CircuitBreaker\Storage\StorageInterface;

final class CircuitBreakerStrategy implements StrategyInterface
{
    public function __construct(
        private readonly CircuitBreakerOptions   $options,
        private readonly StorageInterface        $storage,
        private readonly EventDispatcherInterface $dispatcher,
    ) {}

    public function execute(callable $next): mixed
    {
        $data = $this->storage->get($this->options->name);
        $data = $this->transitionIfNeeded($data);

        return match ($data->state) {
            CircuitState::Open     => throw new CircuitOpenException($this->options->name),
            CircuitState::Closed,
            CircuitState::HalfOpen => $this->callThrough($next, $data),
        };
    }

    private function transitionIfNeeded(CircuitBreakerData $data): CircuitBreakerData
    {
        if ($data->state !== CircuitState::Open) {
            return $data;
        }

        $elapsed = time() - ($data->openedAt?->getTimestamp() ?? 0);
        if ($elapsed < $this->options->resetAfter->toSeconds()) {
            return $data;
        }

        $data = $data->withState(CircuitState::HalfOpen)->withSuccessCount(0);
        $this->storage->save($this->options->name, $data);
        $this->dispatcher->dispatch(new CircuitHalfOpened($this->options->name));

        return $data;
    }

    private function callThrough(callable $next, CircuitBreakerData $data): mixed
    {
        try {
            $result = $next();
            $this->onSuccess($data);
            return $result;
        } catch (\Throwable $e) {
            if ($this->isIgnored($e)) {
                throw $e;
            }
            $this->onFailure($data, $e);
            throw $e;
        }
    }

    private function onSuccess(CircuitBreakerData $data): void
    {
        if ($data->state === CircuitState::HalfOpen) {
            $successes = $data->successCount + 1;
            if ($successes >= $this->options->successThreshold) {
                $next = $data->withState(CircuitState::Closed)->withFailureCount(0)->withSuccessCount(0)->withOpenedAt(null);
                $this->storage->save($this->options->name, $next);
                $this->dispatcher->dispatch(new CircuitClosed($this->options->name));
            } else {
                $this->storage->save($this->options->name, $data->withSuccessCount($successes));
            }
            return;
        }

        if ($data->failureCount > 0) {
            $this->storage->save($this->options->name, $data->withFailureCount(0));
        }
    }

    private function onFailure(CircuitBreakerData $data, \Throwable $e): void
    {
        $failures = $data->failureCount + 1;

        if ($failures >= $this->options->failureThreshold || $data->state === CircuitState::HalfOpen) {
            $next = $data->withState(CircuitState::Open)
                ->withFailureCount($failures)
                ->withSuccessCount(0)
                ->withOpenedAt(new \DateTimeImmutable());
            $this->storage->save($this->options->name, $next);
            $this->dispatcher->dispatch(new CircuitOpened($this->options->name, $e));
        } else {
            $this->storage->save($this->options->name, $data->withFailureCount($failures));
        }
    }

    private function isIgnored(\Throwable $e): bool
    {
        foreach ($this->options->ignoreExceptions as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }
        return false;
    }
}
