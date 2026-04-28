# Aegis

[日本語ドキュメント](#日本語ドキュメント)

A PHP 8.2+ resilience library that combines **Retry**, **Circuit Breaker**, **Timeout**, **Rate Limiter**, and **Bulkhead** into a single composable pipeline.

Inspired by [Resilience4j](https://resilience4j.readme.io/) and [.NET Polly](https://www.pollydocs.org/) — the PHP ecosystem's missing equivalent.

```php
$pipeline = ResiliencePipeline::builder()
    ->circuitBreaker('payment-api', failureThreshold: 5)
    ->retry(maxAttempts: 3, backoff: ExponentialBackoff::withJitter(Duration::milliseconds(100)))
    ->timeout(Duration::seconds(5))
    ->build();

$result = $pipeline->execute(fn() => $httpClient->post('/charge', $payload));
```

## Why Aegis?

PHP has several individual resilience libraries, but none that combine them into a composable pipeline:

| Library | Retry | Circuit Breaker | Timeout | Rate Limiter | Bulkhead | Composable | PHP 8.2+ |
| --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `ackintosh/ganesha` | ✗ | ✅ | ✗ | ✗ | ✗ | ✗ | ✅ |
| `yohang/finite` | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✅ |
| `cline/retry` | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✅ |
| **Aegis** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

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

### Timeout

Enforce a maximum execution duration.

```php
->timeout(Duration::seconds(5))
```

> **Note:** On Unix systems with the `pcntl` extension, Aegis uses `SIGALRM` for true preemptive interruption. On Windows and environments without `pcntl`, elapsed time is checked after execution — useful for limiting total retry budgets.

### Rate Limiter

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

### Composing strategies

Strategies wrap each other in the order they are added (first = outermost).
The recommended order is: **Bulkhead → Rate Limiter → Timeout → Circuit Breaker → Retry**.

```php
$pipeline = ResiliencePipeline::builder()
    ->bulkhead('svc', maxConcurrent: 10)       // 1. Concurrency gate
    ->rateLimit('svc', limit: 100)             // 2. Rate gate
    ->timeout(Duration::seconds(10))           // 3. Total time budget
    ->circuitBreaker('svc', failureThreshold: 5)  // 4. Block if unhealthy
    ->retry(maxAttempts: 3)                    // 5. Retry transient failures
    ->build();
```

### PSR-14 Events

Observe what happens inside the pipeline by wiring up a PSR-14 event dispatcher.

```php
use Aegis\Event\RetryAttempted;
use Aegis\Event\CircuitOpened;
use Aegis\Event\CircuitClosed;
use Aegis\Event\CircuitHalfOpened;
use Aegis\Event\RateLimitExceeded;
use Aegis\Event\BulkheadRejected;

$pipeline = ResiliencePipeline::builder()
    ->withEventDispatcher($dispatcher)
    ->circuitBreaker('api')
    ->retry(maxAttempts: 3)
    ->build();

// Example: log every retry attempt
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

- [x] Rate Limiter
- [x] Bulkhead (concurrency limiting)
- [ ] PHPStan 2.x upgrade
- [ ] Fallback strategy

## License

MIT

---

## 日本語ドキュメント

[↑ English](#aegis)

PHP 8.2+ 向けのレジリエンスライブラリです。**リトライ**・**サーキットブレーカー**・**タイムアウト**・**レートリミッター**・**バルクヘッド**を単一のコンポーザブルなパイプラインとして組み合わせられます。

[Resilience4j](https://resilience4j.readme.io/)（Java）や[.NET Polly](https://www.pollydocs.org/) に相当するものが PHP エコシステムに存在しなかったため作成しました。

```php
$pipeline = ResiliencePipeline::builder()
    ->circuitBreaker('payment-api', failureThreshold: 5)
    ->retry(maxAttempts: 3, backoff: ExponentialBackoff::withJitter(Duration::milliseconds(100)))
    ->timeout(Duration::seconds(5))
    ->build();

$result = $pipeline->execute(fn() => $httpClient->post('/charge', $payload));
```

### なぜ Aegis？

PHP には個別のレジリエンスライブラリが存在しますが、それらをパイプラインとして合成できるものはありませんでした。

| ライブラリ | リトライ | サーキットブレーカー | タイムアウト | レートリミッター | バルクヘッド | 合成可能 | PHP 8.2+ |
| --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `ackintosh/ganesha` | ✗ | ✅ | ✗ | ✗ | ✗ | ✗ | ✅ |
| `yohang/finite` | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✅ |
| `cline/retry` | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✅ |
| **Aegis** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

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
        maxAttempts: 3,                                                          // 最大試行回数（初回 + リトライ）
        backoff: ExponentialBackoff::withJitter(Duration::milliseconds(100)),    // ジッター付き指数バックオフ
        retryOn: [\RuntimeException::class],                                     // リトライ対象の例外
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
    retryIf: fn(\Throwable $e) => $e->getCode() >= 500,  // HTTP 5xx のみリトライ
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
        failureThreshold: 5,           // 5 回連続失敗でオープン
        successThreshold: 2,           // HalfOpen で 2 回連続成功でクローズ
        resetAfter: Duration::seconds(30), // オープン後 30 秒でリセット試行
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

| 状態 | 意味 |
| --- | --- |
| **Closed** | 通常運転。失敗をカウント |
| **Open** | 全呼び出しを即座にブロック（`CircuitOpenException`） |
| **HalfOpen** | 回復を試行中。限定的に呼び出しを通す |

**リクエスト間で状態を永続化（Redis・APCu など）:**

```php
use Aegis\Strategy\CircuitBreaker\Storage\Psr16Storage;

$pipeline = ResiliencePipeline::builder()
    ->circuitBreaker('payment-api', storage: new Psr16Storage($redisCache))
    ->build();
```

**特定の例外をカウント対象外にする（例：バリデーションエラーは障害扱いしない）:**

```php
->circuitBreaker('api', ignoreExceptions: [\InvalidArgumentException::class])
```

#### タイムアウト

処理の最大実行時間を設定します。

```php
->timeout(Duration::seconds(5))
```

> **注意:** `pcntl` 拡張が利用可能な Unix 環境では `SIGALRM` によるプリエンプティブな割り込みを行います。Windows や `pcntl` が使えない環境では、実行後に経過時間をチェックする方式にフォールバックします（リトライ全体の時間制限として機能します）。

#### レートリミッター

固定ウィンドウ内の呼び出し回数を制限します。

```php
use Aegis\ResiliencePipeline;
use Aegis\Duration;

$pipeline = ResiliencePipeline::builder()
    ->rateLimit(
        name: 'payment-api',
        limit: 100,                      // ウィンドウあたり最大 100 回
        window: Duration::seconds(60),
    )
    ->build();
```

**リクエスト間で状態を永続化（Redis・APCu など）:**

```php
use Aegis\Strategy\RateLimiter\Storage\Psr16Storage;

$pipeline = ResiliencePipeline::builder()
    ->rateLimit('payment-api', limit: 100, storage: new Psr16Storage($redisCache))
    ->build();
```

> **注意:** デフォルトの `InMemoryStorage` はプロセス単位のスコープです。PHP-FPM の複数ワーカーをまたいでレート制限するには、Redis や APCu をバックエンドとした `Psr16Storage` を使用してください。

#### バルクヘッド

同時実行数を制限してリソース枯渇を防ぎます。

```php
use Aegis\ResiliencePipeline;

$pipeline = ResiliencePipeline::builder()
    ->bulkhead(
        name: 'database',
        maxConcurrent: 10,   // 最大 10 並行まで許可
    )
    ->build();
```

**リクエスト間で状態を永続化（Redis・APCu など）:**

```php
use Aegis\Strategy\Bulkhead\Storage\Psr16Storage;

$pipeline = ResiliencePipeline::builder()
    ->bulkhead('database', maxConcurrent: 10, storage: new Psr16Storage($redisCache))
    ->build();
```

> **注意:** デフォルトの `InMemoryStorage` はプロセス単位のスコープです。複数ワーカーをまたいだ同時実行数の制御には `Psr16Storage` を使用してください。

#### 戦略の合成

戦略は追加した順に外側から適用されます（最初に追加 = 最も外側）。
推奨順序は **Bulkhead → Rate Limiter → Timeout → Circuit Breaker → Retry** です。

```php
$pipeline = ResiliencePipeline::builder()
    ->bulkhead('svc', maxConcurrent: 10)        // 1. 同時実行数ゲート
    ->rateLimit('svc', limit: 100)              // 2. レートゲート
    ->timeout(Duration::seconds(10))            // 3. 全体の時間制限
    ->circuitBreaker('svc', failureThreshold: 5)  // 4. 不健全なら即ブロック
    ->retry(maxAttempts: 3)                     // 5. 一時的な失敗をリトライ
    ->build();
```

#### PSR-14 イベント

PSR-14 のイベントディスパッチャーを接続して、パイプライン内部の動作を観測できます。

```php
use Aegis\Event\RetryAttempted;
use Aegis\Event\CircuitOpened;
use Aegis\Event\CircuitClosed;
use Aegis\Event\CircuitHalfOpened;
use Aegis\Event\RateLimitExceeded;
use Aegis\Event\BulkheadRejected;

$pipeline = ResiliencePipeline::builder()
    ->withEventDispatcher($dispatcher)
    ->circuitBreaker('api')
    ->retry(maxAttempts: 3)
    ->build();

// リトライ発生時にログを記録する例
// 注意: リスナー登録の API（listen / addListener / subscribeTo）は PSR-14 実装によって異なります
$dispatcher->listen(RetryAttempted::class, function (RetryAttempted $e) use ($logger): void {
    $logger->warning('リトライ実行', [
        'attempt'  => $e->attempt,
        'max'      => $e->maxAttempts,
        'delay_ms' => $e->delayMs,
        'error'    => $e->cause->getMessage(),
    ]);
});
```

| イベント | 発火タイミング |
| --- | --- |
| `RetryAttempted` | リトライ待機前 |
| `CircuitOpened` | Closed → Open に遷移したとき |
| `CircuitClosed` | HalfOpen → Closed に遷移したとき |
| `CircuitHalfOpened` | Open → HalfOpen に遷移したとき |
| `RateLimitExceeded` | レート制限に達して呼び出しを拒否したとき |
| `BulkheadRejected` | バルクヘッドが満杯で呼び出しを拒否したとき |

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

### PHPStan 連携

Aegis には静的解析時に誤用を検出する PHPStan ルールが同梱されています。

**ルール: `retryOn` には `Throwable` のサブクラスのみ指定可能**

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

- [x] Rate Limiter（レート制限）
- [x] Bulkhead（同時実行数制限）
- [ ] PHPStan 2.x 対応
- [ ] Fallback（フォールバック）戦略
