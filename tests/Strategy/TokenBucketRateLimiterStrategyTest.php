<?php

declare(strict_types=1);

namespace Aegis\Tests\Strategy;

use PHPUnit\Framework\TestCase;
use Aegis\Duration;
use Aegis\Exception\TokenBucketExhaustedException;
use Aegis\NullEventDispatcher;
use Aegis\Strategy\RateLimiter\TokenBucketData;
use Aegis\Strategy\RateLimiter\TokenBucketOptions;
use Aegis\Strategy\RateLimiter\TokenBucketRateLimiterStrategy;
use Aegis\Strategy\RateLimiter\Storage\TokenBucketInMemoryStorage;

final class TokenBucketRateLimiterStrategyTest extends TestCase
{
    private function makeStrategy(
        int      $capacity = 5,
        int      $refillRate = 1,
        ?Duration $refillPeriod = null,
        ?TokenBucketInMemoryStorage $storage = null,
    ): TokenBucketRateLimiterStrategy {
        return new TokenBucketRateLimiterStrategy(
            new TokenBucketOptions('test', $capacity, $refillRate, $refillPeriod ?? Duration::seconds(1)),
            $storage ?? new TokenBucketInMemoryStorage(),
            new NullEventDispatcher(),
        );
    }

    public function test_returns_result_on_success(): void
    {
        $result = $this->makeStrategy()->execute(fn () => 'ok');

        $this->assertSame('ok', $result);
    }

    public function test_allows_calls_up_to_capacity(): void
    {
        $strategy = $this->makeStrategy(capacity: 3);

        for ($i = 0; $i < 3; $i++) {
            $strategy->execute(fn () => null);
        }

        $this->expectException(TokenBucketExhaustedException::class);
        $strategy->execute(fn () => null);
    }

    public function test_throws_when_bucket_is_empty(): void
    {
        $storage = new TokenBucketInMemoryStorage();
        // Pre-set empty bucket
        $storage->save('test', new TokenBucketData(0.0, (int)(microtime(true) * 1000)));
        $strategy = $this->makeStrategy(storage: $storage);

        $this->expectException(TokenBucketExhaustedException::class);
        $strategy->execute(fn () => null);
    }

    public function test_refills_tokens_over_time(): void
    {
        $storage = new TokenBucketInMemoryStorage();
        // Pre-set 0 tokens but with lastRefillMs 2 seconds ago (refillRate=5/s → +10 tokens)
        $pastMs = (int)(microtime(true) * 1000) - 2000;
        $storage->save('test', new TokenBucketData(0.0, $pastMs));

        // capacity=5, refillRate=5, period=1s → after 2s: +10 tokens, capped at 5
        $strategy = $this->makeStrategy(capacity: 5, refillRate: 5, storage: $storage);

        $result = $strategy->execute(fn () => 'refilled');
        $this->assertSame('refilled', $result);
    }

    public function test_exception_message_contains_name_and_capacity(): void
    {
        $storage = new TokenBucketInMemoryStorage();
        $storage->save('my-api', new TokenBucketData(0.0, (int)(microtime(true) * 1000)));
        $strategy = new TokenBucketRateLimiterStrategy(
            new TokenBucketOptions('my-api', 10, 1, Duration::seconds(1)),
            $storage,
            new NullEventDispatcher(),
        );

        try {
            $strategy->execute(fn () => null);
            $this->fail('Expected TokenBucketExhaustedException');
        } catch (TokenBucketExhaustedException $e) {
            $this->assertStringContainsString('my-api', $e->getMessage());
            $this->assertStringContainsString('10', $e->getMessage());
        }
    }
}
