<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter\Storage;

use Psr\SimpleCache\CacheInterface;
use Aegis\Strategy\RateLimiter\TokenBucketData;

final readonly class TokenBucketPsr16Storage implements TokenBucketStorageInterface
{
    public function __construct(
        private CacheInterface $cache,
        private string         $keyPrefix = 'aegis_tb_',
    ) {}

    public function get(string $name): ?TokenBucketData
    {
        $value = $this->cache->get($this->key($name));
        return $value instanceof TokenBucketData ? $value : null;
    }

    public function save(string $name, TokenBucketData $data): void
    {
        $this->cache->set($this->key($name), $data, null);
    }

    private function key(string $name): string
    {
        return $this->keyPrefix . $name;
    }
}
