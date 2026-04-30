<?php

declare(strict_types=1);

namespace Aegis\Strategy\Fallback;

use Aegis\Contract\StrategyInterface;
use Aegis\Exception\FallbackNotAvailableException;

final class FallbackStrategy implements StrategyInterface
{
    /**
     * @param callable(\Throwable): mixed $handler
     */
    public function __construct(
        private readonly mixed $handler,
    ) {}

    public function execute(callable $next): mixed
    {
        try {
            return $next();
        } catch (\Throwable $original) {
            try {
                return ($this->handler)($original);
            } catch (\Throwable $fallbackException) {
                throw new FallbackNotAvailableException($original, $fallbackException);
            }
        }
    }
}
