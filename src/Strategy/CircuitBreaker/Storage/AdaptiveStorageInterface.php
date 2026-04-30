<?php

declare(strict_types=1);

namespace Aegis\Strategy\CircuitBreaker\Storage;

use Aegis\Strategy\CircuitBreaker\AdaptiveCircuitBreakerData;

interface AdaptiveStorageInterface
{
    public function get(string $name): AdaptiveCircuitBreakerData;

    public function save(string $name, AdaptiveCircuitBreakerData $data): void;
}
