<?php

declare(strict_types=1);

namespace Aegis\Strategy\CircuitBreaker\Storage;

use Aegis\Strategy\CircuitBreaker\AdaptiveCircuitBreakerData;

final class AdaptiveInMemoryStorage implements AdaptiveStorageInterface
{
    /** @var array<string, AdaptiveCircuitBreakerData> */
    private array $store = [];

    public function get(string $name): AdaptiveCircuitBreakerData
    {
        return $this->store[$name] ?? new AdaptiveCircuitBreakerData();
    }

    public function save(string $name, AdaptiveCircuitBreakerData $data): void
    {
        $this->store[$name] = $data;
    }
}
