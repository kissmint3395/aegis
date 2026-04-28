<?php

declare(strict_types=1);

namespace Aegis\Contract;

use Aegis\Duration;

interface BackoffInterface
{
    /**
     * Return delay duration for the given attempt number (1-based).
     */
    public function delay(int $attempt): Duration;
}
