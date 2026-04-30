<?php

declare(strict_types=1);

namespace Aegis\Tests\Strategy;

use PHPUnit\Framework\TestCase;
use Aegis\Duration;
use Aegis\NullEventDispatcher;
use Aegis\Strategy\Cache\CacheOptions;
use Aegis\Strategy\Cache\CacheStrategy;
use Aegis\Strategy\Cache\Storage\CacheInMemoryStorage;

final class CacheStrategyTest extends TestCase
{
    private function makeStrategy(
        bool                  $staleOnFailure = false,
        int                   $ttlMs = 60_000,
        ?CacheInMemoryStorage $storage = null,
    ): CacheStrategy {
        return new CacheStrategy(
            new CacheOptions(
                name: 'test',
                storage: $storage ?? new CacheInMemoryStorage(),
                ttl: Duration::milliseconds($ttlMs),
                staleOnFailure: $staleOnFailure,
            ),
            new NullEventDispatcher(),
        );
    }

    public function test_returns_result_and_caches_it(): void
    {
        $calls    = 0;
        $storage  = new CacheInMemoryStorage();
        $strategy = $this->makeStrategy(storage: $storage);

        $result = $strategy->execute(function () use (&$calls) {
            $calls++;
            return 'fresh-value';
        });

        $this->assertSame('fresh-value', $result);
        $this->assertSame(1, $calls);

        // Second call should hit cache
        $result2 = $strategy->execute(function () use (&$calls) {
            $calls++;
            return 'should-not-be-called';
        });

        $this->assertSame('fresh-value', $result2);
        $this->assertSame(1, $calls);
    }

    public function test_calls_next_when_cache_is_empty(): void
    {
        $called = false;
        $result = $this->makeStrategy()->execute(function () use (&$called) {
            $called = true;
            return 42;
        });

        $this->assertSame(42, $result);
        $this->assertTrue($called);
    }

    public function test_propagates_exception_when_stale_on_failure_disabled(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('downstream error');

        $this->makeStrategy(staleOnFailure: false)->execute(function () {
            throw new \RuntimeException('downstream error');
        });
    }

    public function test_returns_stale_value_on_failure_when_enabled(): void
    {
        $storage  = new CacheInMemoryStorage();
        $strategy = $this->makeStrategy(staleOnFailure: true, ttlMs: 1, storage: $storage);

        // Populate cache
        $strategy->execute(fn () => 'stale-value');

        // Wait for TTL to expire (1ms)
        usleep(2000);

        // Next call fails but stale cache exists
        $result = $strategy->execute(function () {
            throw new \RuntimeException('downstream error');
        });

        $this->assertSame('stale-value', $result);
    }

    public function test_throws_when_stale_on_failure_but_no_cache(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->makeStrategy(staleOnFailure: true)->execute(function () {
            throw new \RuntimeException('fail');
        });
    }

    public function test_refreshes_cache_after_ttl_expires(): void
    {
        $calls    = 0;
        $storage  = new CacheInMemoryStorage();
        $strategy = $this->makeStrategy(ttlMs: 1, storage: $storage);

        $strategy->execute(function () use (&$calls) {
            $calls++;
            return 'first';
        });

        usleep(2000); // let TTL expire

        $strategy->execute(function () use (&$calls) {
            $calls++;
            return 'second';
        });

        $this->assertSame(2, $calls);
    }
}
