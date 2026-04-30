<?php

declare(strict_types=1);

namespace Aegis\Strategy\Cache\Storage;

use Psr\SimpleCache\CacheInterface;
use Aegis\Strategy\Cache\CachedEntry;

final readonly class CachePsr16Storage implements CacheStorageInterface
{
    public function __construct(
        private CacheInterface $cache,
        private string         $keyPrefix = 'aegis_cache_',
    ) {}

    public function get(string $name): ?CachedEntry
    {
        $value = $this->cache->get($this->key($name));
        return $value instanceof CachedEntry ? $value : null;
    }

    public function set(string $name, CachedEntry $entry): void
    {
        $this->cache->set($this->key($name), $entry, null);
    }

    public function delete(string $name): void
    {
        $this->cache->delete($this->key($name));
    }

    private function key(string $name): string
    {
        return $this->keyPrefix . $name;
    }
}
