<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter\Storage;

use Aegis\Strategy\RateLimiter\TokenBucketData;

interface TokenBucketStorageInterface
{
    public function get(string $name): ?TokenBucketData;

    public function save(string $name, TokenBucketData $data): void;
}
