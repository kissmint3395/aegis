<?php

declare(strict_types=1);

namespace Aegis\Tests\Strategy;

use PHPUnit\Framework\TestCase;
use Aegis\Exception\BulkheadFullException;
use Aegis\NullEventDispatcher;
use Aegis\Strategy\Bulkhead\BulkheadData;
use Aegis\Strategy\Bulkhead\BulkheadOptions;
use Aegis\Strategy\Bulkhead\BulkheadStrategy;
use Aegis\Strategy\Bulkhead\Storage\InMemoryStorage;

final class BulkheadStrategyTest extends TestCase
{
    private function makeStrategy(int $maxConcurrent = 2, ?InMemoryStorage $storage = null): BulkheadStrategy
    {
        return new BulkheadStrategy(
            new BulkheadOptions('test', $maxConcurrent),
            $storage ?? new InMemoryStorage(),
            new NullEventDispatcher(),
        );
    }

    public function test_returns_result_on_success(): void
    {
        $result = $this->makeStrategy()->execute(fn () => 'ok');

        $this->assertSame('ok', $result);
    }

    public function test_throws_when_at_max_concurrent(): void
    {
        $storage = new InMemoryStorage();
        $storage->save('test', new BulkheadData(concurrent: 2));
        $strategy = $this->makeStrategy(maxConcurrent: 2, storage: $storage);

        $this->expectException(BulkheadFullException::class);
        $strategy->execute(fn () => null);
    }

    public function test_releases_slot_after_successful_execution(): void
    {
        $strategy = $this->makeStrategy(maxConcurrent: 1);

        $strategy->execute(fn () => null);
        $result = $strategy->execute(fn () => 'second');

        $this->assertSame('second', $result);
    }

    public function test_releases_slot_after_exception(): void
    {
        $strategy = $this->makeStrategy(maxConcurrent: 1);

        try {
            $strategy->execute(fn (): never => throw new \RuntimeException('fail'));
        } catch (\RuntimeException) {}

        $result = $strategy->execute(fn () => 'recovered');
        $this->assertSame('recovered', $result);
    }

    public function test_exception_message_contains_name_and_limit(): void
    {
        $storage = new InMemoryStorage();
        $storage->save('my-svc', new BulkheadData(concurrent: 3));
        $strategy = new BulkheadStrategy(
            new BulkheadOptions('my-svc', 3),
            $storage,
            new NullEventDispatcher(),
        );

        try {
            $strategy->execute(fn () => null);
            $this->fail('Expected BulkheadFullException');
        } catch (BulkheadFullException $e) {
            $this->assertStringContainsString('my-svc', $e->getMessage());
            $this->assertStringContainsString('3', $e->getMessage());
        }
    }
}
