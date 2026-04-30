<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter\Storage;

use Aegis\Strategy\RateLimiter\TokenBucketData;

final class TokenBucketInMemoryStorage implements TokenBucketStorageInterface
{
    /** @var array<string, TokenBucketData> */
    private array $store = [];

    public function get(string $name): ?TokenBucketData
    {
        return $this->store[$name] ?? null;
    }

    public function save(string $name, TokenBucketData $data): void
    {
        $this->store[$name] = $data;
    }
}
