<?php

declare(strict_types=1);

namespace Aegis\Strategy\CircuitBreaker;

final readonly class CircuitBreakerData
{
    public function __construct(
        public CircuitState         $state = CircuitState::Closed,
        public int                  $failureCount = 0,
        public int                  $successCount = 0,
        public ?\DateTimeImmutable  $openedAt = null,
    ) {}

    public function withState(CircuitState $state): self
    {
        return new self($state, $this->failureCount, $this->successCount, $this->openedAt);
    }

    public function withFailureCount(int $count): self
    {
        return new self($this->state, $count, $this->successCount, $this->openedAt);
    }

    public function withSuccessCount(int $count): self
    {
        return new self($this->state, $this->failureCount, $count, $this->openedAt);
    }

    public function withOpenedAt(?\DateTimeImmutable $at): self
    {
        return new self($this->state, $this->failureCount, $this->successCount, $at);
    }
}
