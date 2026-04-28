<?php

declare(strict_types=1);

namespace Aegis\Strategy\Bulkhead;

use Psr\EventDispatcher\EventDispatcherInterface;
use Aegis\Contract\StrategyInterface;
use Aegis\Event\BulkheadRejected;
use Aegis\Exception\BulkheadFullException;
use Aegis\Strategy\Bulkhead\Storage\StorageInterface;

final class BulkheadStrategy implements StrategyInterface
{
    public function __construct(
        private readonly BulkheadOptions          $options,
        private readonly StorageInterface         $storage,
        private readonly EventDispatcherInterface $dispatcher,
    ) {}

    public function execute(callable $next): mixed
    {
        $data = $this->storage->get($this->options->name);

        if ($data->concurrent >= $this->options->maxConcurrent) {
            $this->dispatcher->dispatch(new BulkheadRejected($this->options->name, $this->options->maxConcurrent));
            throw new BulkheadFullException($this->options->name, $this->options->maxConcurrent);
        }

        $this->storage->save($this->options->name, $data->withConcurrent($data->concurrent + 1));

        try {
            return $next();
        } finally {
            $current = $this->storage->get($this->options->name);
            $this->storage->save($this->options->name, $current->withConcurrent(max(0, $current->concurrent - 1)));
        }
    }
}
