<?php

declare(strict_types=1);

namespace Aegis\Strategy\CircuitBreaker;

final readonly class AdaptiveCircuitBreakerData
{
    /**
     * @param list<bool> $window Ring buffer: true = success, false = failure
     */
    public function __construct(
        public array               $window = [],
        public CircuitState        $state = CircuitState::Closed,
        public ?\DateTimeImmutable $openedAt = null,
        public int                 $halfOpenSuccesses = 0,
    ) {}

    public function withResult(bool $success, int $maxWindowSize): self
    {
        $window = [...$this->window, $success];
        if (count($window) > $maxWindowSize) {
            $window = array_values(array_slice($window, -$maxWindowSize));
        }
        return new self($window, $this->state, $this->openedAt, $this->halfOpenSuccesses);
    }

    public function failureRate(): float
    {
        if ($this->window === []) {
            return 0.0;
        }
        $failures = count(array_filter($this->window, fn (bool $r) => !$r));
        return ($failures / count($this->window)) * 100.0;
    }

    public function callCount(): int
    {
        return count($this->window);
    }

    public function withState(CircuitState $state): self
    {
        return new self($this->window, $state, $this->openedAt, $this->halfOpenSuccesses);
    }

    public function withOpenedAt(?\DateTimeImmutable $openedAt): self
    {
        return new self($this->window, $this->state, $openedAt, $this->halfOpenSuccesses);
    }

    public function withHalfOpenSuccesses(int $count): self
    {
        return new self($this->window, $this->state, $this->openedAt, $count);
    }

    public function withResetWindow(): self
    {
        return new self([], $this->state, $this->openedAt, $this->halfOpenSuccesses);
    }
}
