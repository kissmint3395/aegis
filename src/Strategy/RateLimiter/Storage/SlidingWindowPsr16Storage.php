<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter\Storage;

use Psr\SimpleCache\CacheInterface;
use Aegis\Strategy\RateLimiter\SlidingWindowData;

final readonly class SlidingWindowPsr16Storage implements SlidingWindowStorageInterface
{
    public function __construct(
        private CacheInterface $cache,
        private string         $keyPrefix = 'aegis_sw_',
    ) {}

    public function get(string $name): SlidingWindowData
    {
        $value = $this->cache->get($this->key($name));

        if (!$value instanceof SlidingWindowData) {
            return new SlidingWindowData();
        }

        return $value;
    }

    public function save(string $name, SlidingWindowData $data): void
    {
        $this->cache->set($this->key($name), $data, null);
    }

    private function key(string $name): string
    {
        return $this->keyPrefix . $name;
    }
}
