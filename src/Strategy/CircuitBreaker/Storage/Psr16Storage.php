<?php

declare(strict_types=1);

namespace Aegis\Strategy\CircuitBreaker\Storage;

use Psr\SimpleCache\CacheInterface;
use Aegis\Strategy\CircuitBreaker\CircuitBreakerData;

final readonly class Psr16Storage implements StorageInterface
{
    private const TTL = null; // Persistent by default

    public function __construct(
        private CacheInterface $cache,
        private string         $keyPrefix = 'aegis_cb_',
    ) {}

    public function get(string $name): CircuitBreakerData
    {
        $value = $this->cache->get($this->key($name));

        if (!$value instanceof CircuitBreakerData) {
            return new CircuitBreakerData();
        }

        return $value;
    }

    public function save(string $name, CircuitBreakerData $data): void
    {
        $this->cache->set($this->key($name), $data, self::TTL);
    }

    private function key(string $name): string
    {
        return $this->keyPrefix . $name;
    }
}
