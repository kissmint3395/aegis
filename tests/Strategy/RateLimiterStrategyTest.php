<?php

declare(strict_types=1);

namespace Aegis\Tests\Strategy;

use PHPUnit\Framework\TestCase;
use Aegis\Duration;
use Aegis\Exception\RateLimitExceededException;
use Aegis\NullEventDispatcher;
use Aegis\Strategy\RateLimiter\RateLimiterData;
use Aegis\Strategy\RateLimiter\RateLimiterOptions;
use Aegis\Strategy\RateLimiter\RateLimiterStrategy;
use Aegis\Strategy\RateLimiter\Storage\InMemoryStorage;

final class RateLimiterStrategyTest extends TestCase
{
    private function makeStrategy(int $limit = 5, ?Duration $window = null, ?InMemoryStorage $storage = null): RateLimiterStrategy
    {
        return new RateLimiterStrategy(
            new RateLimiterOptions('test', $limit, $window ?? Duration::seconds(60)),
            $storage ?? new InMemoryStorage(),
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

    public function test_throws_on_first_call_when_limit_is_zero_equivalent(): void
    {
        $storage = new InMemoryStorage();
        $storage->save('test', new RateLimiterData(count: 1, windowStart: new \DateTimeImmutable()));
        $strategy = $this->makeStrategy(limit: 1, storage: $storage);

        $this->expectException(RateLimitExceededException::class);
        $strategy->execute(fn () => null);
    }

    public function test_resets_count_after_window_expiry(): void
    {
        $storage = new InMemoryStorage();
        $storage->save('test', new RateLimiterData(count: 5, windowStart: new \DateTimeImmutable('-2 seconds')));
        $strategy = $this->makeStrategy(limit: 5, window: Duration::seconds(1), storage: $storage);

        $result = $strategy->execute(fn () => 'after reset');

        $this->assertSame('after reset', $result);
    }

    public function test_exception_message_contains_name_and_limit(): void
    {
        $strategy = new RateLimiterStrategy(
            new RateLimiterOptions('my-api', 1, Duration::seconds(60)),
            new InMemoryStorage(),
            new NullEventDispatcher(),
        );
        $strategy->execute(fn () => null);

        try {
            $strategy->execute(fn () => null);
            $this->fail('Expected RateLimitExceededException');
        } catch (RateLimitExceededException $e) {
            $this->assertStringContainsString('my-api', $e->getMessage());
            $this->assertStringContainsString('1', $e->getMessage());
        }
    }
}
