<?php

declare(strict_types=1);

namespace Aegis\Strategy\Cache\Storage;

use Aegis\Strategy\Cache\CachedEntry;

interface CacheStorageInterface
{
    public function get(string $name): ?CachedEntry;

    public function set(string $name, CachedEntry $entry): void;

    public function delete(string $name): void;
}
