<?php

declare(strict_types=1);

namespace Aegis\Tests\Strategy;

use PHPUnit\Framework\TestCase;
use Aegis\Duration;
use Aegis\Exception\HedgeExhaustedException;
use Aegis\NullEventDispatcher;
use Aegis\Strategy\Hedge\HedgeOptions;
use Aegis\Strategy\Hedge\HedgeStrategy;

final class HedgeStrategyTest extends TestCase
{
    private function makeStrategy(int $maxHedges = 1, int $delayMs = 0): HedgeStrategy
    {
        return new HedgeStrategy(
            new HedgeOptions(Duration::milliseconds($delayMs), $maxHedges),
            new NullEventDispatcher(),
        );
    }

    public function test_returns_result_when_primary_succeeds(): void
    {
        $result = $this->makeStrategy()->execute(fn () => 'ok');

        $this->assertSame('ok', $result);
    }

    public function test_returns_hedge_result_when_primary_fails(): void
    {
        $calls = 0;
        $result = $this->makeStrategy(maxHedges: 1)->execute(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('primary failed');
            }
            return 'hedge-ok';
        });

        $this->assertSame('hedge-ok', $result);
        $this->assertSame(2, $calls);
    }

    public function test_throws_when_all_attempts_fail(): void
    {
        $this->expectException(HedgeExhaustedException::class);

        $this->makeStrategy(maxHedges: 1)->execute(function () {
            throw new \RuntimeException('always fails');
        });
    }

    public function test_exception_contains_all_throwables(): void
    {
        $e1 = new \RuntimeException('first');
        $e2 = new \RuntimeException('second');
        $calls = 0;

        try {
            $this->makeStrategy(maxHedges: 1)->execute(function () use (&$calls, $e1, $e2) {
                $calls++;
                throw $calls === 1 ? $e1 : $e2;
            });
            $this->fail('Expected HedgeExhaustedException');
        } catch (HedgeExhaustedException $ex) {
            $this->assertCount(2, $ex->getExceptions());
            $this->assertSame($e1, $ex->getExceptions()[0]);
            $this->assertSame($e2, $ex->getExceptions()[1]);
        }
    }

    public function test_throws_immediately_when_max_hedges_is_zero(): void
    {
        $this->expectException(HedgeExhaustedException::class);

        $this->makeStrategy(maxHedges: 0)->execute(function () {
            throw new \RuntimeException('fail');
        });
    }

    public function test_returns_first_success_with_multiple_hedges(): void
    {
        $calls = 0;
        $result = $this->makeStrategy(maxHedges: 2)->execute(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw new \RuntimeException("fail $calls");
            }
            return 'third-ok';
        });

        $this->assertSame('third-ok', $result);
        $this->assertSame(3, $calls);
    }
}
