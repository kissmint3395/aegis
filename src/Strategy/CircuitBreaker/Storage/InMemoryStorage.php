<?php

declare(strict_types=1);

namespace Aegis\Strategy\CircuitBreaker\Storage;

use Aegis\Strategy\CircuitBreaker\CircuitBreakerData;

final class InMemoryStorage implements StorageInterface
{
    /** @var array<string, CircuitBreakerData> */
    private array $store = [];

    public function get(string $name): CircuitBreakerData
    {
        return $this->store[$name] ?? new CircuitBreakerData();
    }

    public function save(string $name, CircuitBreakerData $data): void
    {
        $this->store[$name] = $data;
    }
}
