<?php

declare(strict_types=1);

namespace Aegis\Strategy\Bulkhead\Storage;

use Psr\SimpleCache\CacheInterface;
use Aegis\Strategy\Bulkhead\BulkheadData;

final readonly class Psr16Storage implements StorageInterface
{
    public function __construct(
        private CacheInterface $cache,
        private string         $keyPrefix = 'aegis_bh_',
    ) {}

    public function get(string $name): BulkheadData
    {
        $value = $this->cache->get($this->key($name));

        if (!$value instanceof BulkheadData) {
            return new BulkheadData();
        }

        return $value;
    }

    public function save(string $name, BulkheadData $data): void
    {
        $this->cache->set($this->key($name), $data, null);
    }

    private function key(string $name): string
    {
        return $this->keyPrefix . $name;
    }
}
