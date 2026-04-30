<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter\Storage;

use Aegis\Strategy\RateLimiter\SlidingWindowData;

final class SlidingWindowInMemoryStorage implements SlidingWindowStorageInterface
{
    /** @var array<string, SlidingWindowData> */
    private array $store = [];

    public function get(string $name): SlidingWindowData
    {
        return $this->store[$name] ?? new SlidingWindowData();
    }

    public function save(string $name, SlidingWindowData $data): void
    {
        $this->store[$name] = $data;
    }
}
