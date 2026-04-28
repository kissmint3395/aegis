<?php

declare(strict_types=1);

namespace Aegis\Exception;

use Aegis\Duration;

final class TimeoutExceededException extends \RuntimeException
{
    public function __construct(Duration $timeout)
    {
        parent::__construct(sprintf('Execution timed out after %dms.', $timeout->toMilliseconds()));
    }
}
