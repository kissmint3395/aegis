<?php

declare(strict_types=1);

namespace Aegis\Tests\Strategy;

use PHPUnit\Framework\TestCase;
use Aegis\Duration;
use Aegis\Exception\CircuitOpenException;
use Aegis\NullEventDispatcher;
use Aegis\Strategy\CircuitBreaker\CircuitBreakerOptions;
use Aegis\Strategy\CircuitBreaker\CircuitBreakerStrategy;
use Aegis\Strategy\CircuitBreaker\CircuitState;
use Aegis\Strategy\CircuitBreaker\Storage\InMemoryStorage;

final class CircuitBreakerStrategyTest extends TestCase
{
    private function makeStrategy(
        int $failureThreshold = 3,
        int $successThreshold = 2,
        ?Duration $resetAfter = null,
        array $ignoreExceptions = [],
    ): CircuitBreakerStrategy {
        return new CircuitBreakerStrategy(
            new CircuitBreakerOptions(
                name: 'test',
                failureThreshold: $failureThreshold,
                successThreshold: $successThreshold,
                resetAfter: $resetAfter ?? Duration::seconds(60),
                ignoreExceptions: $ignoreExceptions,
            ),
            new InMemoryStorage(),
            new NullEventDispatcher(),
        );
    }

    public function test_passes_through_on_success(): void
    {
        $strategy = $this->makeStrategy();

        $result = $strategy->execute(fn () => 'ok');

        $this->assertSame('ok', $result);
    }

    public function test_opens_after_failure_threshold(): void
    {
        $strategy = $this->makeStrategy(failureThreshold: 3);

        for ($i = 0; $i < 3; $i++) {
            try {
                $strategy->execute(fn (): never => throw new \RuntimeException('fail'));
            } catch (\RuntimeException) {
                // expected
            }
        }

        $this->expectException(CircuitOpenException::class);
        $strategy->execute(fn () => 'should be blocked');
    }

    public function test_ignored_exception_does_not_trip_circuit(): void
    {
        $strategy = $this->makeStrategy(
            failureThreshold: 2,
            ignoreExceptions: [\InvalidArgumentException::class],
        );

        for ($i = 0; $i < 5; $i++) {
            try {
                $strategy->execute(fn (): never => throw new \InvalidArgumentException('ignored'));
            } catch (\InvalidArgumentException) {
                // expected
            }
        }

        // Circuit must still be closed — next call should pass
        $result = $strategy->execute(fn () => 'ok');
        $this->assertSame('ok', $result);
    }

    public function test_half_open_closes_after_success_threshold(): void
    {
        $storage = new InMemoryStorage();
        $options = new CircuitBreakerOptions(
            name: 'test',
            failureThreshold: 1,
            successThreshold: 2,
            resetAfter: Duration::seconds(0),
        );
        $strategy = new CircuitBreakerStrategy($options, $storage, new NullEventDispatcher());

        // Trip the circuit
        try {
            $strategy->execute(fn (): never => throw new \RuntimeException('fail'));
        } catch (\RuntimeException) {}

        // Verify it's open
        $this->assertSame(CircuitState::Open, $storage->get('test')->state);

        // resetAfter=0 → immediate HalfOpen transition
        $strategy->execute(fn () => 'probe 1');
        $strategy->execute(fn () => 'probe 2');

        $this->assertSame(CircuitState::Closed, $storage->get('test')->state);
    }

    public function test_half_open_reopens_on_failure(): void
    {
        $storage = new InMemoryStorage();
        $options = new CircuitBreakerOptions(
            name: 'test',
            failureThreshold: 1,
            successThreshold: 2,
            resetAfter: Duration::seconds(0),
        );
        $strategy = new CircuitBreakerStrategy($options, $storage, new NullEventDispatcher());

        // Trip
        try {
            $strategy->execute(fn (): never => throw new \RuntimeException('fail'));
        } catch (\RuntimeException) {}

        // Fail during HalfOpen
        try {
            $strategy->execute(fn (): never => throw new \RuntimeException('fail again'));
        } catch (\RuntimeException) {}

        $this->assertSame(CircuitState::Open, $storage->get('test')->state);
    }
}
