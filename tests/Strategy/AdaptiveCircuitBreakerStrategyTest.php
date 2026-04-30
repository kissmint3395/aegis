<?php

declare(strict_types=1);

namespace Aegis\Tests\Strategy;

use PHPUnit\Framework\TestCase;
use Aegis\Duration;
use Aegis\Exception\CircuitOpenException;
use Aegis\NullEventDispatcher;
use Aegis\Strategy\CircuitBreaker\AdaptiveCircuitBreakerOptions;
use Aegis\Strategy\CircuitBreaker\AdaptiveCircuitBreakerStrategy;
use Aegis\Strategy\CircuitBreaker\AdaptiveCircuitBreakerData;
use Aegis\Strategy\CircuitBreaker\CircuitState;
use Aegis\Strategy\CircuitBreaker\Storage\AdaptiveInMemoryStorage;

final class AdaptiveCircuitBreakerStrategyTest extends TestCase
{
    private function makeStrategy(
        int     $windowSize = 10,
        float   $failureRateThreshold = 50.0,
        int     $minimumCalls = 5,
        int     $successThreshold = 2,
        ?AdaptiveInMemoryStorage $storage = null,
    ): AdaptiveCircuitBreakerStrategy {
        return new AdaptiveCircuitBreakerStrategy(
            new AdaptiveCircuitBreakerOptions(
                name: 'test',
                windowSize: $windowSize,
                failureRateThreshold: $failureRateThreshold,
                minimumCalls: $minimumCalls,
                successThreshold: $successThreshold,
            ),
            $storage ?? new AdaptiveInMemoryStorage(),
            new NullEventDispatcher(),
        );
    }

    public function test_returns_result_on_success(): void
    {
        $result = $this->makeStrategy()->execute(fn () => 'ok');

        $this->assertSame('ok', $result);
    }

    public function test_stays_closed_below_failure_rate_threshold(): void
    {
        $strategy = $this->makeStrategy(windowSize: 10, failureRateThreshold: 60.0, minimumCalls: 5);

        // 2 failures out of 5 = 40%, below 60% threshold
        for ($i = 0; $i < 3; $i++) {
            $strategy->execute(fn () => 'ok');
        }
        for ($i = 0; $i < 2; $i++) {
            try {
                $strategy->execute(fn (): never => throw new \RuntimeException('fail'));
            } catch (\RuntimeException) {}
        }

        // Circuit should still be closed
        $result = $strategy->execute(fn () => 'still open');
        $this->assertSame('still open', $result);
    }

    public function test_opens_when_failure_rate_exceeds_threshold(): void
    {
        $strategy = $this->makeStrategy(windowSize: 10, failureRateThreshold: 50.0, minimumCalls: 5);

        // 3 failures out of 5 = 60%, above 50% threshold
        for ($i = 0; $i < 2; $i++) {
            $strategy->execute(fn () => 'ok');
        }
        for ($i = 0; $i < 3; $i++) {
            try {
                $strategy->execute(fn (): never => throw new \RuntimeException('fail'));
            } catch (\RuntimeException) {}
        }

        $this->expectException(CircuitOpenException::class);
        $strategy->execute(fn () => 'blocked');
    }

    public function test_does_not_open_before_minimum_calls(): void
    {
        $strategy = $this->makeStrategy(windowSize: 10, failureRateThreshold: 50.0, minimumCalls: 10);

        // 5 failures but minimumCalls not yet reached
        for ($i = 0; $i < 5; $i++) {
            try {
                $strategy->execute(fn (): never => throw new \RuntimeException('fail'));
            } catch (\RuntimeException) {}
        }

        // Should still be closed
        $result = $strategy->execute(fn () => 'not blocked');
        $this->assertSame('not blocked', $result);
    }

    public function test_transitions_to_half_open_after_reset_period(): void
    {
        $storage  = new AdaptiveInMemoryStorage();
        $strategy = $this->makeStrategy(storage: $storage);

        // Pre-set Open state with openedAt in the past
        $storage->save('test', (new AdaptiveCircuitBreakerData())
            ->withState(CircuitState::Open)
            ->withOpenedAt(new \DateTimeImmutable('-60 seconds')));

        // Should not throw; circuit should have transitioned to HalfOpen
        $result = $strategy->execute(fn () => 'half-open call');
        $this->assertSame('half-open call', $result);
    }

    public function test_closes_after_success_threshold_in_half_open(): void
    {
        $storage  = new AdaptiveInMemoryStorage();
        $strategy = $this->makeStrategy(successThreshold: 2, storage: $storage);

        $storage->save('test', (new AdaptiveCircuitBreakerData())
            ->withState(CircuitState::Open)
            ->withOpenedAt(new \DateTimeImmutable('-60 seconds')));

        // Two successes in HalfOpen should close the circuit
        $strategy->execute(fn () => 'ok');
        $strategy->execute(fn () => 'ok');

        // Should be fully closed now — no exception
        $result = $strategy->execute(fn () => 'closed');
        $this->assertSame('closed', $result);
    }

    public function test_reopens_on_failure_in_half_open(): void
    {
        $storage  = new AdaptiveInMemoryStorage();
        $strategy = $this->makeStrategy(storage: $storage);

        $storage->save('test', (new AdaptiveCircuitBreakerData())
            ->withState(CircuitState::Open)
            ->withOpenedAt(new \DateTimeImmutable('-60 seconds')));

        try {
            $strategy->execute(fn (): never => throw new \RuntimeException('fail in half-open'));
        } catch (\RuntimeException) {}

        $this->expectException(CircuitOpenException::class);
        $strategy->execute(fn () => 'blocked again');
    }
}
