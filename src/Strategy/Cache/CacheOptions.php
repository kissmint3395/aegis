<?php

declare(strict_types=1);

namespace Aegis\Strategy\Cache;

use Aegis\Duration;
use Aegis\Strategy\Cache\Storage\CacheStorageInterface;

final readonly class CacheOptions
{
    public function __construct(
        public string                $name,
        public CacheStorageInterface $storage,
        public Duration              $ttl,
        public bool                  $staleOnFailure = false,
    ) {}
}
