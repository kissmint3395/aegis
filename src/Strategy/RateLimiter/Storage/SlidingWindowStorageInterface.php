<?php

declare(strict_types=1);

namespace Aegis\Strategy\RateLimiter\Storage;

use Aegis\Strategy\RateLimiter\SlidingWindowData;

interface SlidingWindowStorageInterface
{
    public function get(string $name): SlidingWindowData;

    public function save(string $name, SlidingWindowData $data): void;
}
