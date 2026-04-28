<?php

declare(strict_types=1);

namespace Aegis\Tests;

use PHPUnit\Framework\TestCase;
use Aegis\Duration;
use Aegis\Exception\CircuitOpenException;
use Aegis\Exception\RetryExhaustedException;
use Aegis\ResiliencePipeline;

final class ResiliencePipelineTest extends TestCase
{
    public function test_executes_successfully_with_no_strategies(): void
    {
        $pipeline = ResiliencePipeline::builder()->build();

        $result = $pipeline->execute(fn () => 42);

        $this->assertSame(42, $result);
    }

    public function test_retry_and_circuit_breaker_composed(): void
    {
        $calls = 0;

        $pipeline = ResiliencePipeline::builder()
            ->circuitBreaker('svc', failureThreshold: 10)
            ->retry(maxAttempts: 3)
            ->build();

        $result = $pipeline->execute(function () use (&$calls): string {
            $calls++;
            if ($calls < 3) {
                throw new \RuntimeException('transient');
            }
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls);
    }

    public function test_circuit_opens_after_exhausting_retries(): void
    {
        $pipeline = ResiliencePipeline::builder()
            ->circuitBreaker('svc', failureThreshold: 2, resetAfter: Duration::seconds(60))
            ->retry(maxAttempts: 2)
            ->build();

        // First call: retry exhausted → CB records 1 failure (RetryExhausted is a Throwable)
        for ($trip = 0; $trip < 2; $trip++) {
            try {
                $pipeline->execute(fn (): never => throw new \RuntimeException('fail'));
            } catch (RetryExhaustedException) {}
        }

        $this->expectException(CircuitOpenException::class);
        $pipeline->execute(fn () => 'blocked');
    }

    public function test_timeout_strategy_is_included_in_pipeline(): void
    {
        $pipeline = ResiliencePipeline::builder()
            ->timeout(Duration::seconds(5))
            ->build();

        // Should not throw for fast operations
        $result = $pipeline->execute(fn () => 'fast');
        $this->assertSame('fast', $result);
    }
}
