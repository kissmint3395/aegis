<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter\Storage;

use Psr\SimpleCache\CacheInterface;
use Aegis\Strategy\RateLimiter\RateLimiterData;

final readonly class Psr16Storage implements StorageInterface
{
    public function __construct(
        private CacheInterface $cache,
        private string         $keyPrefix = 'aegis_rl_',
    ) {}

    public function get(string $name): RateLimiterData
    {
        $value = $this->cache->get($this->key($name));

        if (!$value instanceof RateLimiterData) {
            return new RateLimiterData();
        }

        return $value;
    }

    public function save(string $name, RateLimiterData $data): void
    {
        $this->cache->set($this->key($name), $data, null);
    }

    private function key(string $name): string
    {
        return $this->keyPrefix . $name;
    }
}
