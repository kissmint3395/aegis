<?php

declare(strict_types=1);

namespace Aegis\Strategy\CircuitBreaker\Storage;

use Aegis\Strategy\CircuitBreaker\CircuitBreakerData;

interface StorageInterface
{
    public function get(string $name): CircuitBreakerData;

    public function save(string $name, CircuitBreakerData $data): void;
}
