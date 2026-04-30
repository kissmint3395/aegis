<?php

declare(strict_types=1);

namespace Aegis\Exception;

final class FallbackNotAvailableException extends \RuntimeException
{
    public function __construct(
        private readonly \Throwable $originalException,
        \Throwable $fallbackException,
    ) {
        parent::__construct(
            sprintf('Fallback also failed: %s', $fallbackException->getMessage()),
            0,
            $fallbackException,
        );
    }

    public function getOriginalException(): \Throwable
    {
        return $this->originalException;
    }
}
