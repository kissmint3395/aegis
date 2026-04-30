<?php

declare(strict_types=1);

namespace Aegis\Strategy\CircuitBreaker\Storage;

use Psr\SimpleCache\CacheInterface;
use Aegis\Strategy\CircuitBreaker\AdaptiveCircuitBreakerData;

final readonly class AdaptivePsr16Storage implements AdaptiveStorageInterface
{
    public function __construct(
        private CacheInterface $cache,
        private string         $keyPrefix = 'aegis_acb_',
    ) {}

    public function get(string $name): AdaptiveCircuitBreakerData
    {
        $value = $this->cache->get($this->key($name));

        if (!$value instanceof AdaptiveCircuitBreakerData) {
            return new AdaptiveCircuitBreakerData();
        }

        return $value;
    }

    public function save(string $name, AdaptiveCircuitBreakerData $data): void
    {
        $this->cache->set($this->key($name), $data, null);
    }

    private function key(string $name): string
    {
        return $this->keyPrefix . $name;
    }
}
