<?php

declare(strict_types=1);

namespace Aegis\Strategy\CircuitBreaker;

use Psr\EventDispatcher\EventDispatcherInterface;
use Aegis\Contract\StrategyInterface;
use Aegis\Event\CircuitClosed;
use Aegis\Event\CircuitHalfOpened;
use Aegis\Event\CircuitOpened;
use Aegis\Exception\CircuitOpenException;
use Aegis\Strategy\CircuitBreaker\Storage\AdaptiveStorageInterface;

final class AdaptiveCircuitBreakerStrategy implements StrategyInterface
{
    public function __construct(
        private readonly AdaptiveCircuitBreakerOptions $options,
        private readonly AdaptiveStorageInterface      $storage,
        private readonly EventDispatcherInterface      $dispatcher,
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

    private function transitionIfNeeded(AdaptiveCircuitBreakerData $data): AdaptiveCircuitBreakerData
    {
        if ($data->state !== CircuitState::Open) {
            return $data;
        }

        $elapsed = time() - ($data->openedAt?->getTimestamp() ?? 0);
        if ($elapsed < $this->options->resetAfter->toSeconds()) {
            return $data;
        }

        $data = $data->withState(CircuitState::HalfOpen)->withHalfOpenSuccesses(0)->withResetWindow();
        $this->storage->save($this->options->name, $data);
        $this->dispatcher->dispatch(new CircuitHalfOpened($this->options->name));

        return $data;
    }

    private function callThrough(callable $next, AdaptiveCircuitBreakerData $data): mixed
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

    private function onSuccess(AdaptiveCircuitBreakerData $data): void
    {
        if ($data->state === CircuitState::HalfOpen) {
            $successes = $data->halfOpenSuccesses + 1;
            if ($successes >= $this->options->successThreshold) {
                $closed = $data->withState(CircuitState::Closed)->withOpenedAt(null)->withHalfOpenSuccesses(0)->withResetWindow();
                $this->storage->save($this->options->name, $closed);
                $this->dispatcher->dispatch(new CircuitClosed($this->options->name));
            } else {
                $this->storage->save($this->options->name, $data->withHalfOpenSuccesses($successes));
            }
            return;
        }

        $updated = $data->withResult(true, $this->options->windowSize);
        $this->storage->save($this->options->name, $updated);
    }

    private function onFailure(AdaptiveCircuitBreakerData $data, \Throwable $e): void
    {
        if ($data->state === CircuitState::HalfOpen) {
            $opened = $data->withState(CircuitState::Open)->withOpenedAt(new \DateTimeImmutable())->withHalfOpenSuccesses(0);
            $this->storage->save($this->options->name, $opened);
            $this->dispatcher->dispatch(new CircuitOpened($this->options->name, $e));
            return;
        }

        $updated     = $data->withResult(false, $this->options->windowSize);
        $shouldOpen  = $updated->callCount() >= $this->options->minimumCalls
            && $updated->failureRate() >= $this->options->failureRateThreshold;

        if ($shouldOpen) {
            $opened = $updated->withState(CircuitState::Open)->withOpenedAt(new \DateTimeImmutable());
            $this->storage->save($this->options->name, $opened);
            $this->dispatcher->dispatch(new CircuitOpened($this->options->name, $e));
        } else {
            $this->storage->save($this->options->name, $updated);
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
