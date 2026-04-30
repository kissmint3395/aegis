<?php

declare(strict_types=1);

namespace Aegis\Strategy\Cache\Storage;

use Aegis\Strategy\Cache\CachedEntry;

final class CacheInMemoryStorage implements CacheStorageInterface
{
    /** @var array<string, CachedEntry> */
    private array $store = [];

    public function get(string $name): ?CachedEntry
    {
        return $this->store[$name] ?? null;
    }

    public function set(string $name, CachedEntry $entry): void
    {
        $this->store[$name] = $entry;
    }

    public function delete(string $name): void
    {
        unset($this->store[$name]);
    }
}
