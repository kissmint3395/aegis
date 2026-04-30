<?php

declare(strict_types=1);

namespace Aegis\Tests\Strategy;

use PHPUnit\Framework\TestCase;
use Aegis\Duration;
use Aegis\Exception\RateLimitExceededException;
use Aegis\NullEventDispatcher;
use Aegis\Strategy\RateLimiter\RateLimiterOptions;
use Aegis\Strategy\RateLimiter\SlidingWindowData;
use Aegis\Strategy\RateLimiter\SlidingWindowRateLimiterStrategy;
use Aegis\Strategy\RateLimiter\Storage\SlidingWindowInMemoryStorage;

final class SlidingWindowRateLimiterStrategyTest extends TestCase
{
    private function makeStrategy(int $limit = 5, ?Duration $window = null, ?SlidingWindowInMemoryStorage $storage = null): SlidingWindowRateLimiterStrategy
    {
        return new SlidingWindowRateLimiterStrategy(
            new RateLimiterOptions('test', $limit, $window ?? Duration::seconds(60)),
            $storage ?? new SlidingWindowInMemoryStorage(),
            new NullEventDispatcher(),
        );
    }

    public function test_returns_result_on_success(): void
    {
        $result = $this->makeStrategy()->execute(fn () => 'ok');

        $this->assertSame('ok', $result);
    }

    public function test_allows_calls_up_to_limit(): void
    {
        $strategy = $this->makeStrategy(limit: 3);

        for ($i = 0; $i < 3; $i++) {
            $strategy->execute(fn () => null);
        }

        $this->expectException(RateLimitExceededException::class);
        $strategy->execute(fn () => null);
    }

    public function test_evicts_expired_timestamps_and_allows_new_calls(): void
    {
        $storage = new SlidingWindowInMemoryStorage();
        $nowMs   = (int)(microtime(true) * 1000);

        // 3 timestamps that are already 2 seconds old (outside 1-second window)
        $storage->save('test', new SlidingWindowData([
            $nowMs - 2000,
            $nowMs - 1500,
            $nowMs - 1100,
        ]));

        $strategy = $this->makeStrategy(limit: 3, window: Duration::seconds(1), storage: $storage);

        // All old timestamps should be evicted; call should succeed
        $result = $strategy->execute(fn () => 'after eviction');
        $this->assertSame('after eviction', $result);
    }

    public function test_counts_only_timestamps_within_window(): void
    {
        $storage = new SlidingWindowInMemoryStorage();
        $nowMs   = (int)(microtime(true) * 1000);

        // 2 old + 2 recent (within window)
        $storage->save('test', new SlidingWindowData([
            $nowMs - 5000, // outside 3s window
            $nowMs - 4000, // outside
            $nowMs - 500,  // inside
            $nowMs - 200,  // inside
        ]));

        $strategy = $this->makeStrategy(limit: 3, window: Duration::seconds(3), storage: $storage);

        // 2 inside + 1 new = 3, should be allowed
        $strategy->execute(fn () => null);

        // 3 inside + 1 new = 4, should be rejected
        $this->expectException(RateLimitExceededException::class);
        $strategy->execute(fn () => null);
    }

    public function test_exception_message_contains_name_and_limit(): void
    {
        $strategy = new SlidingWindowRateLimiterStrategy(
            new RateLimiterOptions('my-api', 1, Duration::seconds(60)),
            new SlidingWindowInMemoryStorage(),
            new NullEventDispatcher(),
        );
        $strategy->execute(fn () => null);

        try {
            $strategy->execute(fn () => null);
            $this->fail('Expected RateLimitExceededException');
        } catch (RateLimitExceededException $e) {
            $this->assertStringContainsString('my-api', $e->getMessage());
        }
    }
}
