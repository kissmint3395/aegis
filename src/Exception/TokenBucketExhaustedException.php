<?php

declare(strict_types=1);

namespace Aegis\Exception;

final class TokenBucketExhaustedException extends \RuntimeException
{
    public function __construct(string $name, int $capacity)
    {
        parent::__construct(sprintf('Token bucket "%s" is exhausted (capacity: %d).', $name, $capacity));
    }
}
