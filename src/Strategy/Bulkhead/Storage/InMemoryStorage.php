<?php

declare(strict_types=1);

namespace Aegis\Strategy\Bulkhead\Storage;

use Aegis\Strategy\Bulkhead\BulkheadData;

final class InMemoryStorage implements StorageInterface
{
    /** @var array<string, BulkheadData> */
    private array $store = [];

    public function get(string $name): BulkheadData
    {
        return $this->store[$name] ?? new BulkheadData();
    }

    public function save(string $name, BulkheadData $data): void
    {
        $this->store[$name] = $data;
    }
}
