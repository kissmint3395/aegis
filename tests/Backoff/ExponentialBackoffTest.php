<?php

declare(strict_types=1);

namespace Aegis\Tests\Backoff;

use PHPUnit\Framework\TestCase;
use Aegis\Backoff\ExponentialBackoff;
use Aegis\Backoff\FixedBackoff;
use Aegis\Duration;

final class ExponentialBackoffTest extends TestCase
{
    public function test_doubles_delay_each_attempt(): void
    {
        $backoff = ExponentialBackoff::create(Duration::milliseconds(100));

        $this->assertSame(100, $backoff->delay(1)->toMilliseconds());
        $this->assertSame(200, $backoff->delay(2)->toMilliseconds());
        $this->assertSame(400, $backoff->delay(3)->toMilliseconds());
    }

    public function test_caps_at_max_delay(): void
    {
        $backoff = ExponentialBackoff::create(
            baseDelay: Duration::milliseconds(100),
            maxDelay: Duration::milliseconds(300),
        );

        $this->assertSame(100, $backoff->delay(1)->toMilliseconds());
        $this->assertSame(200, $backoff->delay(2)->toMilliseconds());
        $this->assertSame(300, $backoff->delay(3)->toMilliseconds()); // capped
        $this->assertSame(300, $backoff->delay(4)->toMilliseconds()); // capped
    }

    public function test_jitter_stays_within_bounds(): void
    {
        $backoff = ExponentialBackoff::withJitter(Duration::milliseconds(100));

        for ($i = 0; $i < 50; $i++) {
            $ms = $backoff->delay(1)->toMilliseconds();
            $this->assertGreaterThanOrEqual(50, $ms);
            $this->assertLessThanOrEqual(100, $ms);
        }
    }

    public function test_fixed_backoff_always_returns_same_delay(): void
    {
        $backoff = new FixedBackoff(Duration::milliseconds(200));

        $this->assertSame(200, $backoff->delay(1)->toMilliseconds());
        $this->assertSame(200, $backoff->delay(5)->toMilliseconds());
    }
}
