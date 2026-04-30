<?php

declare(strict_types=1);

namespace Aegis\Strategy\Cache;

use Aegis\Contract\StrategyInterface;

final class CacheStrategy implements StrategyInterface
{
    public function __construct(
        private readonly CacheOptions $options,
    ) {}

    public function execute(callable $next): mixed
    {
        $nowMs = (int)(microtime(true) * 1000);
        $entry = $this->options->storage->get($this->options->name);

        if ($entry !== null && $entry->isFresh($nowMs)) {
            return $entry->value;
        }

        try {
            $result = $next();
        } catch (\Throwable $e) {
            if ($this->options->staleOnFailure && $entry !== null) {
                return $entry->value;
            }
            throw $e;
        }

        $freshUntilMs = $nowMs + $this->options->ttl->toMilliseconds();
        $this->options->storage->set($this->options->name, new CachedEntry($result, $freshUntilMs));

        return $result;
    }
}
