<?php

declare(strict_types=1);

namespace Aegis\Exception;

final class BulkheadFullException extends \RuntimeException
{
    public function __construct(string $name, int $maxConcurrent)
    {
        parent::__construct(sprintf('Bulkhead "%s" is full — maximum %d concurrent calls allowed.', $name, $maxConcurrent));
    }
}
