<?php

declare(strict_types=1);

namespace Aegis\Exception;

final class RateLimitExceededException extends \RuntimeException
{
    public function __construct(string $name, int $limit)
    {
        parent::__construct(sprintf('Rate limit exceeded for "%s": limit of %d calls per window.', $name, $limit));
    }
}
