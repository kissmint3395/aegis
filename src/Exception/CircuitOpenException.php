<?php

declare(strict_types=1);

namespace Aegis\Exception;

final class CircuitOpenException extends \RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct(sprintf('Circuit "%s" is open — calls are blocked.', $name));
    }
}
