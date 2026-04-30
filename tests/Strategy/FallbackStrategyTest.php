<?php

declare(strict_types=1);

namespace Aegis\Tests\Strategy;

use PHPUnit\Framework\TestCase;
use Aegis\Exception\FallbackNotAvailableException;
use Aegis\Strategy\Fallback\FallbackStrategy;

final class FallbackStrategyTest extends TestCase
{
    public function test_returns_result_on_success(): void
    {
        $strategy = new FallbackStrategy(fn(\Throwable $e) => 'fallback');

        $result = $strategy->execute(fn () => 'ok');

        $this->assertSame('ok', $result);
    }

    public function test_returns_fallback_on_failure(): void
    {
        $strategy = new FallbackStrategy(fn(\Throwable $e) => 'default');

        $result = $strategy->execute(fn (): never => throw new \RuntimeException('fail'));

        $this->assertSame('default', $result);
    }

    public function test_fallback_receives_original_exception(): void
    {
        $caught = null;
        $strategy = new FallbackStrategy(function (\Throwable $e) use (&$caught): string {
            $caught = $e;
            return 'handled';
        });

        $strategy->execute(fn (): never => throw new \RuntimeException('original error'));

        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertSame('original error', $caught->getMessage());
    }

    public function test_throws_fallback_not_available_when_handler_also_fails(): void
    {
        $strategy = new FallbackStrategy(
            fn(\Throwable $e) => throw new \RuntimeException('fallback failed'),
        );

        try {
            $strategy->execute(fn (): never => throw new \RuntimeException('original'));
            $this->fail('Expected FallbackNotAvailableException');
        } catch (FallbackNotAvailableException $e) {
            $this->assertSame('original', $e->getOriginalException()->getMessage());
            $this->assertSame('fallback failed', $e->getPrevious()->getMessage());
        }
    }

    public function test_fallback_can_return_null(): void
    {
        $strategy = new FallbackStrategy(fn(\Throwable $e) => null);

        $result = $strategy->execute(fn (): never => throw new \RuntimeException('fail'));

        $this->assertNull($result);
    }
}
