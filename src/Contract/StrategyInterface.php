<?php

declare(strict_types=1);

namespace Aegis\Contract;

interface StrategyInterface
{
    /**
     * Execute the callback through this strategy.
     * Each strategy wraps the next, forming a pipeline.
     *
     * @param callable(): mixed $next
     * @return mixed
     */
    public function execute(callable $next): mixed;
}
