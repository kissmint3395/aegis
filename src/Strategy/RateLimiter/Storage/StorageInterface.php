<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter\Storage;

use Aegis\Strategy\RateLimiter\RateLimiterData;

interface StorageInterface
{
    public function get(string $name): RateLimiterData;

    public function save(string $name, RateLimiterData $data): void;
}
