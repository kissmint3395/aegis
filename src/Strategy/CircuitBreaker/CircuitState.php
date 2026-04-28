<?php

declare(strict_types=1);

namespace Aegis\Strategy\CircuitBreaker;

enum CircuitState
{
    case Closed;   // Normal operation — failures accumulate
    case Open;     // All calls blocked until reset timeout elapses
    case HalfOpen; // Probing recovery — limited calls allowed
}
