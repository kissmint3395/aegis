<?php

declare(strict_types=1);

namespace Aegis;

use Aegis\Contract\StrategyInterface;

final class ResiliencePipeline
{
    /** @param list<StrategyInterface> $strategies Outermost first. */
    private function __construct(
        private readonly array $strategies,
    ) {}

    public static function builder(): ResiliencePipelineBuilder
    {
        return new ResiliencePipelineBuilder();
    }

    /**
     * @internal Used by ResiliencePipelineBuilder.
     * @param list<StrategyInterface> $strategies
     */
    public static function fromStrategies(array $strategies): self
    {
        return new self($strategies);
    }

    /**
     * Execute the callable through the full strategy pipeline.
     *
     * @param callable(): mixed $callback
     * @return mixed
     */
    public function execute(callable $callback): mixed
    {
        $chain = array_reduce(
            array_reverse($this->strategies),
            static fn (callable $carry, StrategyInterface $strategy): callable =>
                static fn () => $strategy->execute($carry),
            $callback,
        );

        return ($chain)();
    }
}
