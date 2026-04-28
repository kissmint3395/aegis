<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter\Storage;

use Aegis\Strategy\RateLimiter\RateLimiterData;

final class InMemoryStorage implements StorageInterface
{
    /** @var array<string, RateLimiterData> */
    private array $store = [];

    public function get(string $name): RateLimiterData
    {
        return $this->store[$name] ?? new RateLimiterData();
    }

    public function save(string $name, RateLimiterData $data): void
    {
        $this->store[$name] = $data;
    }
}
