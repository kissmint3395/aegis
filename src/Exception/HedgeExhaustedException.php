<?php

declare(strict_types=1);

namespace Aegis\Exception;

final class HedgeExhaustedException extends \RuntimeException
{
    /** @param list<\Throwable> $exceptions */
    public function __construct(private readonly array $exceptions)
    {
        parent::__construct(sprintf('All %d hedge attempt(s) exhausted without success.', count($exceptions)));
    }

    /** @return list<\Throwable> */
    public function getExceptions(): array
    {
        return $this->exceptions;
    }
}
