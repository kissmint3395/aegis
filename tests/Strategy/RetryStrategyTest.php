<?php

declare(strict_types=1);

namespace Aegis\Tests\Strategy;

use PHPUnit\Framework\TestCase;
use Aegis\Backoff\FixedBackoff;
use Aegis\Duration;
use Aegis\Exception\RetryExhaustedException;
use Aegis\NullEventDispatcher;
use Aegis\Strategy\Retry\RetryOptions;
use Aegis\Strategy\Retry\RetryStrategy;

final class RetryStrategyTest extends TestCase
{
    private function makeStrategy(int $maxAttempts = 3, array $retryOn = [\Throwable::class], mixed $retryIf = null): RetryStrategy
    {
        return new RetryStrategy(
            new RetryOptions($maxAttempts, new FixedBackoff(Duration::zero()), $retryOn, $retryIf),
            new NullEventDispatcher(),
        );
    }

    public function test_returns_result_on_success(): void
    {
        $strategy = $this->makeStrategy();

        $result = $strategy->execute(fn () => 'ok');

        $this->assertSame('ok', $result);
    }

    public function test_retries_up_to_max_attempts(): void
    {
        $calls = 0;
        $strategy = $this->makeStrategy(maxAttempts: 3);

        $this->expectException(RetryExhaustedException::class);

        $strategy->execute(function () use (&$calls): never {
            $calls++;
            throw new \RuntimeException('fail');
        });

        $this->assertSame(3, $calls);
    }

    public function test_succeeds_on_second_attempt(): void
    {
        $calls = 0;
        $strategy = $this->makeStrategy(maxAttempts: 3);

        $result = $strategy->execute(function () use (&$calls): string {
            $calls++;
            if ($calls < 2) {
                throw new \RuntimeException('transient');
            }
            return 'recovered';
        });

        $this->assertSame('recovered', $result);
        $this->assertSame(2, $calls);
    }

    public function test_does_not_retry_unmatched_exception(): void
    {
        $strategy = $this->makeStrategy(retryOn: [\InvalidArgumentException::class]);

        $this->expectException(\RuntimeException::class);

        $strategy->execute(function (): never {
            throw new \RuntimeException('not retried');
        });
    }

    public function test_retryIf_predicate_prevents_retry(): void
    {
        $strategy = $this->makeStrategy(
            retryIf: fn (\Throwable $e) => str_contains($e->getMessage(), 'transient'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('permanent');

        $strategy->execute(function (): never {
            throw new \RuntimeException('permanent');
        });
    }

    public function test_retry_exhausted_wraps_last_exception(): void
    {
        $strategy = $this->makeStrategy(maxAttempts: 2);

        try {
            $strategy->execute(fn (): never => throw new \RuntimeException('root cause'));
            $this->fail('Expected RetryExhaustedException');
        } catch (RetryExhaustedException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            $this->assertSame('root cause', $e->getPrevious()->getMessage());
        }
    }
}
