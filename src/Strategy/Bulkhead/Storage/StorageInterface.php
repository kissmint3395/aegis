<?php

declare(strict_types=1);

namespace Aegis\Strategy\Bulkhead\Storage;

use Aegis\Strategy\Bulkhead\BulkheadData;

interface StorageInterface
{
    public function get(string $name): BulkheadData;

    public function save(string $name, BulkheadData $data): void;
}
