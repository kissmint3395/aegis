# Aegis

[日本語ドキュメント](#日本語ドキュメント)

A PHP 8.2+ resilience library that combines **Retry**, **Circuit Breaker**, **Timeout**, **Rate Limiter**, **Bulkhead**, **Fallback**, **Cache**, and **Hedge** into a single composable pipeline.

Inspired by [Resilience4j](https://resilience4j.readme.io/) and [.NET Polly](https://www.pollydocs.org/) — the PHP ecosystem's missing equivalent.

```php
$pipeline = ResiliencePipeline::builder()
    ->circuitBreaker('payment-api', failureThreshold: 5)
    ->retry(maxAttempts: 3, backoff: ExponentialBackoff::withJitter(Duration::milliseconds(100)))
    ->timeout(Duration::seconds(5))
    ->fallback(fn(\Throwable $e) => CachedResponse::last())
    ->build();

$result = $pipeline->execute(fn() => $httpClient->post('/charge', $payload));
```

## Why Aegis?

PHP has several individual resilience libraries, but none that combine them into a composable pipeline:

| Library | Retry | CB | Timeout | Rate Limiter | Bulkhead | Fallback | Cache | Composable | PHP 8.2+ |
| --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `ackintosh/ganesha` | ✗ | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✅ |
| `yohang/finite` | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✅ |
| `cline/retry` | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✅ |
| **Aegis** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

## Requirements

- PHP 8.2+
- `psr/event-dispatcher` ^1.0
- `psr/simple-cache` ^3.0 _(optional, for persistent Circuit Breaker, Rate Limiter, and Bulkhead state)_

## Installation

```bash
composer require kissmint3395/aegis
```

## Usage

### Retry

Retry a failing operation with configurable backoff.

```php
use Aegis\ResiliencePipeline;
use Aegis\Backoff\ExponentialBackoff;
use Aegis\Duration;

$pipeline = ResiliencePipeline::builder()
    ->retry(
        maxAttempts: 3,
        backoff: ExponentialBackoff::withJitter(Duration::milliseconds(100)),
        retryOn: [\RuntimeException::class],
    )
    ->build();

$result = $pipeline->execute(fn() => $api->fetch());
```

**Backoff strategies:**

```php
use Aegis\Backoff\FixedBackoff;
use Aegis\Backoff\ExponentialBackoff;

// Fixed delay
new FixedBackoff(Duration::milliseconds(200))

// Exponential: 100ms → 200ms → 400ms → ...
ExponentialBackoff::create(Duration::milliseconds(100))

// Exponential with jitter: randomised between 50%–100% of each step
ExponentialBackoff::withJitter(Duration::milliseconds(100), maxDelay: Duration::seconds(5))
```

**Conditional retry:**

```php
->retry(
    retryIf: fn(\Throwable $e) => $e->getCode() >= 500,
)
```

### Circuit Breaker

Stop cascading failures by blocking calls when a service is unhealthy.

```php
use Aegis\ResiliencePipeline;
use Aegis\Duration;

$pipeline = ResiliencePipeline::builder()
    ->circuitBreaker(
        name: 'inventory-api',
        failureThreshold: 5,   // Open after 5 consecutive failures
        successThreshold: 2,   // Close after 2 consecutive successes in HalfOpen
        resetAfter: Duration::seconds(30),
    )
    ->build();
```

**State transitions:**

```
Closed ──(5 failures)──► Open ──(30s elapsed)──► HalfOpen
  ▲                                                  │
  └──────────(2 successes)───────────────────────────┘
                              (1 failure) ──► Open
```

**Persistent state across requests (Redis, APCu, etc.):**

```php
use Aegis\Strategy\CircuitBreaker\Storage\Psr16Storage;

$pipeline = ResiliencePipeline::builder()
    ->circuitBreaker('payment-api', storage: new Psr16Storage($redisCache))
    ->build();
```

**Ignore specific exceptions (e.g. validation errors should not trip the circuit):**

```php
->circuitBreaker('api', ignoreExceptions: [\InvalidArgumentException::class])
```

### Adaptive Circuit Breaker

A sliding-window variant that opens based on the **failure rate** (%) over the last N calls, rather than a consecutive-failure count. Equivalent to Resilience4j's sliding-window circuit breaker.

```php
$pipeline = ResiliencePipeline::builder()
    ->adaptiveCircuitBreaker(
        name: 'payment-api',
        windowSize: 20,              // Evaluate the last 20 calls
        failureRateThreshold: 50.0,  // Open if ≥ 50% fail
        minimumCalls: 5,             // Don't open until at least 5 calls recorded
        successThreshold: 2,         // Close after 2 successes in HalfOpen
        resetAfter: Duration::seconds(30),
    )
    ->build();
```

**Persistent state:**

```php
use Aegis\Strategy\CircuitBreaker\Storage\AdaptivePsr16Storage;

->adaptiveCircuitBreaker('api', storage: new AdaptivePsr16Storage($redisCache))
```

### Timeout

Enforce a maximum execution duration.

```php
->timeout(Duration::seconds(5))
```

> **Note:** On Unix systems with the `pcntl` extension, Aegis uses `SIGALRM` for true preemptive interruption. On Windows and environments without `pcntl`, elapsed time is checked after execution — useful for limiting total retry budgets.

### Rate Limiter (Fixed Window)

Limit the number of calls within a fixed time window.

```php
use Aegis\ResiliencePipeline;
use Aegis\Duration;

$pipeline = ResiliencePipeline::builder()
    ->rateLimit(
        name: 'payment-api',
        limit: 100,                      // Max 100 calls per window
        window: Duration::seconds(60),
    )
    ->build();
```

**Persistent rate limiting across requests (Redis, APCu, etc.):**

```php
use Aegis\Strategy\RateLimiter\Storage\Psr16Storage;

$pipeline = ResiliencePipeline::builder()
    ->rateLimit('payment-api', limit: 100, storage: new Psr16Storage($redisCache))
    ->build();
```

> **Note:** The default `InMemoryStorage` is process-scoped. For rate limiting across PHP-FPM workers, use `Psr16Storage` backed by Redis or APCu.

### Rate Limiter (Sliding Window)

Eliminates the "boundary burst" problem of fixed-window rate limiting by tracking individual request timestamps.

```php
$pipeline = ResiliencePipeline::builder()
    ->slidingRateLimit(
        name: 'payment-api',
        limit: 100,
        window: Duration::seconds(60),
    )
    ->build();
```

**Persistent state:**

```php
use Aegis\Strategy\RateLimiter\Storage\SlidingWindowPsr16Storage;

->slidingRateLimit('payment-api', limit: 100, storage: new SlidingWindowPsr16Storage($redisCache))
```

> **Note:** Each entry stores a full timestamp array. For very high-volume endpoints, prefer a Redis ZSET-backed implementation.

### Rate Limiter (Token Bucket)

Allows burst traffic up to a `capacity` while enforcing an average rate via continuous token refill. Tokens are added at `refillRate` per `refillPeriod`.

```php
$pipeline = ResiliencePipeline::builder()
    ->tokenBucketRateLimit(
        name: 'payment-api',
        capacity: 20,                          // Max burst size
        refillRate: 10,                        // Tokens added per period
        refillPeriod: Duration::seconds(1),    // Refill interval
    )
    ->build();
```

**Persistent state:**

```php
use Aegis\Strategy\RateLimiter\Storage\TokenBucketPsr16Storage;

->tokenBucketRateLimit('api', capacity: 20, storage: new TokenBucketPsr16Storage($redisCache))
```

### Bulkhead

Limit the number of concurrent executions to prevent resource exhaustion.

```php
use Aegis\ResiliencePipeline;

$pipeline = ResiliencePipeline::builder()
    ->bulkhead(
        name: 'database',
        maxConcurrent: 10,   // Allow at most 10 concurrent calls
    )
    ->build();
```

**Persistent concurrency tracking across requests (Redis, APCu, etc.):**

```php
use Aegis\Strategy\Bulkhead\Storage\Psr16Storage;

$pipeline = ResiliencePipeline::builder()
    ->bulkhead('database', maxConcurrent: 10, storage: new Psr16Storage($redisCache))
    ->build();
```

> **Note:** The default `InMemoryStorage` is process-scoped. For cross-worker concurrency limiting, use `Psr16Storage` backed by Redis or APCu.

### Fallback

Return a default value (or call an alternative callable) when all inner strategies fail.

```php
$pipeline = ResiliencePipeline::builder()
    ->circuitBreaker('payment-api')
    ->retry(maxAttempts: 3)
    ->fallback(fn(\Throwable $e) => ['status' => 'unavailable'])
    ->build();
```

The fallback handler receives the original `\Throwable`. If the handler itself throws, a `FallbackNotAvailableException` is raised wrapping both the original and fallback exceptions.

```php
use Aegis\Exception\FallbackNotAvailableException;

try {
    $result = $pipeline->execute(fn() => $api->fetch());
} catch (FallbackNotAvailableException $e) {
    $original = $e->getOriginalException();
}
```

### Cache

Cache successful results and serve them on subsequent calls. Optionally serve stale entries when the downstream call fails.

```php
use Aegis\Strategy\Cache\Storage\CacheInMemoryStorage;

$storage  = new CacheInMemoryStorage();  // or CachePsr16Storage for persistence

$pipeline = ResiliencePipeline::builder()
    ->cache(
        name: 'product-catalog',
        ttl: Duration::minutes(5),
        staleOnFailure: true,   // Serve expired cache when downstream throws
        storage: $storage,
    )
    ->build();
```

With `staleOnFailure: true`, if the downstream call throws and a (possibly expired) cache entry exists, the stale value is returned instead of propagating the exception.

### Hedge _(experimental)_

Fire additional "hedge" requests after a configurable delay and return the first successful result. Reduces tail latency for operations where some replicas respond faster than others.

```php
$pipeline = ResiliencePipeline::builder()
    ->hedge(
        delay: Duration::milliseconds(200),  // Fire hedge after 200ms
        maxHedges: 1,
    )
    ->build();
```

> **Note:** True latency-based hedging requires callables that cooperatively yield via `Fiber::suspend()` (e.g. ReactPHP / Swoole). For synchronous callables, hedges fire immediately after each failure.

### Composing strategies

Strategies wrap each other in the order they are added (first = outermost).
The recommended order is: **Bulkhead → Rate Limiter → Timeout → Circuit Breaker → Retry → Fallback**.

```php
$pipeline = ResiliencePipeline::builder()
    ->bulkhead('svc', maxConcurrent: 10)          // 1. Concurrency gate
    ->rateLimit('svc', limit: 100)                // 2. Rate gate
    ->timeout(Duration::seconds(10))              // 3. Total time budget
    ->circuitBreaker('svc', failureThreshold: 5)  // 4. Block if unhealthy
    ->retry(maxAttempts: 3)                       // 5. Retry transient failures
    ->fallback(fn() => $defaultValue)             // 6. Last-resort fallback
    ->build();
```

### PSR-14 Events

Observe what happens inside the pipeline by wiring up a PSR-14 event dispatcher.

```php
use Aegis\Event\RetryAttempted;
use Aegis\Event\CircuitOpened;
use Aegis\Event\RateLimitExceeded;
use Aegis\Event\TokenBucketExhausted;

$pipeline = ResiliencePipeline::builder()
    ->withEventDispatcher($dispatcher)
    ->circuitBreaker('api')
    ->retry(maxAttempts: 3)
    ->build();

// Note: listener registration API (listen/addListener/subscribeTo) depends on your PSR-14 implementation.
$dispatcher->listen(RetryAttempted::class, function (RetryAttempted $e) use ($logger): void {
    $logger->warning('Retry attempt', [
        'attempt'   => $e->attempt,
        'max'       => $e->maxAttempts,
        'delay_ms'  => $e->delayMs,
        'error'     => $e->cause->getMessage(),
    ]);
});
```

| Event | Fired when |
| --- | --- |
| `RetryAttempted` | A retry is about to be delayed and re-attempted |
| `CircuitOpened` | Circuit transitions Closed → Open |
| `CircuitClosed` | Circuit transitions HalfOpen → Closed |
| `CircuitHalfOpened` | Circuit transitions Open → HalfOpen |
| `RateLimitExceeded` | A call is rejected because the rate limit is reached |
| `BulkheadRejected` | A call is rejected because the bulkhead is full |
| `TokenBucketExhausted` | A call is rejected because the token bucket is empty |

### Custom strategies

Implement `StrategyInterface` to plug in your own logic.

```php
use Aegis\Contract\StrategyInterface;

final class LoggingStrategy implements StrategyInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function execute(callable $next): mixed
    {
        $start = microtime(true);
        try {
            $result = $next();
            $this->logger->info('OK', ['ms' => (int)((microtime(true) - $start) * 1000)]);
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            throw $e;
        }
    }
}

$pipeline = ResiliencePipeline::builder()
    ->addStrategy(new LoggingStrategy($logger))
    ->retry(maxAttempts: 3)
    ->build();
```

## Exceptions

| Exception | Thrown when |
| --- | --- |
| `RetryExhaustedException` | All retry attempts failed. `getPrevious()` returns the last cause. |
| `CircuitOpenException` | A call is made while the circuit is Open. |
| `TimeoutExceededException` | Execution exceeded the configured duration. |
| `RateLimitExceededException` | The rate limit for the window has been reached. |
| `BulkheadFullException` | The maximum number of concurrent calls is already reached. |
| `TokenBucketExhaustedException` | The token bucket has no tokens available. |
| `FallbackNotAvailableException` | Both the primary call and the fallback handler failed. |
| `HedgeExhaustedException` | All hedge attempts failed. `getExceptions()` returns every cause. |

## PHPStan integration

Aegis ships with a PHPStan rule that catches misuse at analysis time.

**Rule: `retryOn` must contain `Throwable` subclasses**

```php
// PHPStan error: "stdClass" does not implement Throwable
new RetryOptions(retryOn: [\stdClass::class]);
```

Install the extension via `phpstan/extension-installer` (automatic) or add manually:

```neon
# phpstan.neon
includes:
    - vendor/kissmint3395/aegis/phpstan/extension.neon
```

## Development

```bash
composer install

# Tests
./vendor/bin/phpunit

# Static analysis
./vendor/bin/phpstan analyse
```

## Roadmap

- [x] Rate Limiter (Fixed Window, Sliding Window, Token Bucket)
- [x] Bulkhead (concurrency limiting)
- [x] Fallback strategy
- [x] Adaptive Circuit Breaker (sliding-window failure rate)
- [x] Cache strategy
- [x] Hedge strategy (experimental)
- [ ] PHPStan 2.x upgrade

## License

MIT

---

## 日本語ドキュメント

[↑ English](#aegis)

PHP 8.2+ 向けのレジリエンスライブラリです。**リトライ**・**サーキットブレーカー**・**タイムアウト**・**レートリミッター**・**バルクヘッド**・**フォールバック**・**キャッシュ**・**ヘッジ**を単一のコンポーザブルなパイプラインとして組み合わせられます。

[Resilience4j](https://resilience4j.readme.io/)（Java）や[.NET Polly](https://www.pollydocs.org/) に相当するものが PHP エコシステムに存在しなかったため作成しました。

```php
$pipeline = ResiliencePipeline::builder()
    ->circuitBreaker('payment-api', failureThreshold: 5)
    ->retry(maxAttempts: 3, backoff: ExponentialBackoff::withJitter(Duration::milliseconds(100)))
    ->timeout(Duration::seconds(5))
    ->fallback(fn(\Throwable $e) => CachedResponse::last())
    ->build();

$result = $pipeline->execute(fn() => $httpClient->post('/charge', $payload));
```

### なぜ Aegis？

PHP には個別のレジリエンスライブラリが存在しますが、それらをパイプラインとして合成できるものはありませんでした。

| ライブラリ | リトライ | CB | タイムアウト | レートリミッター | バルクヘッド | フォールバック | キャッシュ | 合成可能 | PHP 8.2+ |
| --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `ackintosh/ganesha` | ✗ | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✅ |
| `yohang/finite` | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✅ |
| `cline/retry` | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✅ |
| **Aegis** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

### 要件

- PHP 8.2+
- `psr/event-dispatcher` ^1.0
- `psr/simple-cache` ^3.0 _（任意。サーキットブレーカー・レートリミッター・バルクヘッドの状態を永続化する場合）_

### インストール

```bash
composer require kissmint3395/aegis
```

### 使い方

#### リトライ

失敗した処理をバックオフ戦略付きで再試行します。

```php
use Aegis\ResiliencePipeline;
use Aegis\Backoff\ExponentialBackoff;
use Aegis\Duration;

$pipeline = ResiliencePipeline::builder()
    ->retry(
        maxAttempts: 3,
        backoff: ExponentialBackoff::withJitter(Duration::milliseconds(100)),
        retryOn: [\RuntimeException::class],
    )
    ->build();

$result = $pipeline->execute(fn() => $api->fetch());
```

**バックオフ戦略一覧:**

```php
use Aegis\Backoff\FixedBackoff;
use Aegis\Backoff\ExponentialBackoff;

// 固定遅延
new FixedBackoff(Duration::milliseconds(200))

// 指数バックオフ: 100ms → 200ms → 400ms → ...
ExponentialBackoff::create(Duration::milliseconds(100))

// ジッター付き指数バックオフ: 各ステップの 50〜100% をランダムで選択
ExponentialBackoff::withJitter(Duration::milliseconds(100), maxDelay: Duration::seconds(5))
```

**条件付きリトライ:**

```php
->retry(
    retryIf: fn(\Throwable $e) => $e->getCode() >= 500,
)
```

#### サーキットブレーカー

サービスが不健全なときに呼び出しをブロックし、カスケード障害を防ぎます。

```php
use Aegis\ResiliencePipeline;
use Aegis\Duration;

$pipeline = ResiliencePipeline::builder()
    ->circuitBreaker(
        name: 'inventory-api',
        failureThreshold: 5,
        successThreshold: 2,
        resetAfter: Duration::seconds(30),
    )
    ->build();
```

**状態遷移:**

```
Closed ──（5回失敗）──► Open ──（30秒経過）──► HalfOpen
  ▲                                                │
  └──────────（2回成功）────────────────────────────┘
                            （1回失敗）──► Open
```

**リクエスト間で状態を永続化（Redis・APCu など）:**

```php
use Aegis\Strategy\CircuitBreaker\Storage\Psr16Storage;

$pipeline = ResiliencePipeline::builder()
    ->circuitBreaker('payment-api', storage: new Psr16Storage($redisCache))
    ->build();
```

#### アダプティブ サーキットブレーカー

直近 N 件の**失敗率（%）**に基づいてサーキットを開く、スライディングウィンドウ型のサーキットブレーカーです。

```php
$pipeline = ResiliencePipeline::builder()
    ->adaptiveCircuitBreaker(
        name: 'payment-api',
        windowSize: 20,              // 直近 20 件を評価
        failureRateThreshold: 50.0,  // 50% 以上失敗でオープン
        minimumCalls: 5,             // 5 件以上記録されるまでは評価しない
        successThreshold: 2,
        resetAfter: Duration::seconds(30),
    )
    ->build();
```

#### タイムアウト

処理の最大実行時間を設定します。

```php
->timeout(Duration::seconds(5))
```

> **注意:** `pcntl` 拡張が利用可能な Unix 環境では `SIGALRM` によるプリエンプティブな割り込みを行います。Windows や `pcntl` が使えない環境では、実行後に経過時間をチェックする方式にフォールバックします。

#### レートリミッター（固定ウィンドウ）

固定ウィンドウ内の呼び出し回数を制限します。

```php
$pipeline = ResiliencePipeline::builder()
    ->rateLimit(
        name: 'payment-api',
        limit: 100,
        window: Duration::seconds(60),
    )
    ->build();
```

#### レートリミッター（スライディングウィンドウ）

固定ウィンドウの「境界バースト問題」を解消します。個々のリクエストタイムスタンプを記録して正確にカウントします。

```php
$pipeline = ResiliencePipeline::builder()
    ->slidingRateLimit(
        name: 'payment-api',
        limit: 100,
        window: Duration::seconds(60),
    )
    ->build();
```

#### レートリミッター（トークンバケット）

最大 `capacity` バーストを許容しつつ、`refillRate / refillPeriod` の平均レートを連続補充で維持します。

```php
$pipeline = ResiliencePipeline::builder()
    ->tokenBucketRateLimit(
        name: 'payment-api',
        capacity: 20,
        refillRate: 10,
        refillPeriod: Duration::seconds(1),
    )
    ->build();
```

#### バルクヘッド

同時実行数を制限してリソース枯渇を防ぎます。

```php
$pipeline = ResiliencePipeline::builder()
    ->bulkhead(
        name: 'database',
        maxConcurrent: 10,
    )
    ->build();
```

#### フォールバック

全戦略が失敗したとき、代替値を返すか代替処理を実行します。

```php
$pipeline = ResiliencePipeline::builder()
    ->circuitBreaker('payment-api')
    ->retry(maxAttempts: 3)
    ->fallback(fn(\Throwable $e) => ['status' => 'unavailable'])
    ->build();
```

フォールバックハンドラー自体が例外をスローした場合は `FallbackNotAvailableException` が発生します。

#### キャッシュ

成功したレスポンスをキャッシュし、次回以降は呼び出しをスキップします。`staleOnFailure: true` を指定すると、ダウンストリームが失敗した際に期限切れのキャッシュを返します。

```php
use Aegis\Strategy\Cache\Storage\CacheInMemoryStorage;

$pipeline = ResiliencePipeline::builder()
    ->cache(
        name: 'product-catalog',
        ttl: Duration::minutes(5),
        staleOnFailure: true,
        storage: new CacheInMemoryStorage(),
    )
    ->build();
```

#### ヘッジ（実験的）

一定時間内に応答がない場合、追加のリクエストを並行して投げ、最初に成功したものを採用します（テールレイテンシー対策）。

```php
$pipeline = ResiliencePipeline::builder()
    ->hedge(
        delay: Duration::milliseconds(200),
        maxHedges: 1,
    )
    ->build();
```

> **注意:** 真のレイテンシーベースのヘッジには、`Fiber::suspend()` を使用した協調型非同期（ReactPHP / Swoole など）が必要です。同期的な callable の場合、ヘッジは各失敗後に即座に発火します。

#### 戦略の合成

戦略は追加した順に外側から適用されます（最初に追加 = 最も外側）。
推奨順序は **Bulkhead → Rate Limiter → Timeout → Circuit Breaker → Retry → Fallback** です。

```php
$pipeline = ResiliencePipeline::builder()
    ->bulkhead('svc', maxConcurrent: 10)
    ->rateLimit('svc', limit: 100)
    ->timeout(Duration::seconds(10))
    ->circuitBreaker('svc', failureThreshold: 5)
    ->retry(maxAttempts: 3)
    ->fallback(fn() => $defaultValue)
    ->build();
```

#### PSR-14 イベント

PSR-14 のイベントディスパッチャーを接続して、パイプライン内部の動作を観測できます。

```php
$pipeline = ResiliencePipeline::builder()
    ->withEventDispatcher($dispatcher)
    ->circuitBreaker('api')
    ->retry(maxAttempts: 3)
    ->build();
```

| イベント | 発火タイミング |
| --- | --- |
| `RetryAttempted` | リトライ待機前 |
| `CircuitOpened` | Closed → Open に遷移したとき |
| `CircuitClosed` | HalfOpen → Closed に遷移したとき |
| `CircuitHalfOpened` | Open → HalfOpen に遷移したとき |
| `RateLimitExceeded` | レート制限に達して呼び出しを拒否したとき |
| `BulkheadRejected` | バルクヘッドが満杯で呼び出しを拒否したとき |
| `TokenBucketExhausted` | トークンバケットが空で呼び出しを拒否したとき |

#### カスタム戦略

`StrategyInterface` を実装して独自の戦略を追加できます。

```php
use Aegis\Contract\StrategyInterface;

final class LoggingStrategy implements StrategyInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function execute(callable $next): mixed
    {
        $start = microtime(true);
        try {
            $result = $next();
            $this->logger->info('成功', ['ms' => (int)((microtime(true) - $start) * 1000)]);
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            throw $e;
        }
    }
}

$pipeline = ResiliencePipeline::builder()
    ->addStrategy(new LoggingStrategy($logger))
    ->retry(maxAttempts: 3)
    ->build();
```

### 例外一覧

| 例外 | 発生タイミング |
| --- | --- |
| `RetryExhaustedException` | 全リトライが失敗。`getPrevious()` で最後の原因を取得可能 |
| `CircuitOpenException` | サーキットがオープン状態のときに呼び出しを行った |
| `TimeoutExceededException` | 設定した時間内に処理が完了しなかった |
| `RateLimitExceededException` | ウィンドウ内のレート制限に達した |
| `BulkheadFullException` | 最大同時実行数に達している |
| `TokenBucketExhaustedException` | トークンバケットにトークンがない |
| `FallbackNotAvailableException` | 本処理とフォールバックの両方が失敗した |
| `HedgeExhaustedException` | 全ヘッジ試行が失敗。`getExceptions()` で全原因を取得可能 |

### PHPStan 連携

Aegis には静的解析時に誤用を検出する PHPStan ルールが同梱されています。

```php
// PHPStan エラー: "stdClass" は Throwable を実装していない
new RetryOptions(retryOn: [\stdClass::class]);
```

`phpstan/extension-installer` 経由で自動的に有効化されます。手動で追加する場合:

```neon
# phpstan.neon
includes:
    - vendor/kissmint3395/aegis/phpstan/extension.neon
```

### 開発

```bash
composer install

# テスト実行
./vendor/bin/phpunit

# 静的解析
./vendor/bin/phpstan analyse
```

### ロードマップ

- [x] レートリミッター（固定ウィンドウ・スライディングウィンドウ・トークンバケット）
- [x] バルクヘッド（同時実行数制限）
- [x] フォールバック戦略
- [x] アダプティブ サーキットブレーカー（失敗率ベース）
- [x] キャッシュ戦略
- [x] ヘッジ戦略（実験的）
- [ ] PHPStan 2.x 対応
