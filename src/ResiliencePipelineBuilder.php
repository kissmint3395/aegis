<?php

declare(strict_types=1);

namespace Aegis;

use Psr\EventDispatcher\EventDispatcherInterface;
use Aegis\Backoff\ExponentialBackoff;
use Aegis\Contract\BackoffInterface;
use Aegis\Contract\StrategyInterface;
use Aegis\Strategy\Bulkhead\BulkheadOptions;
use Aegis\Strategy\Bulkhead\BulkheadStrategy;
use Aegis\Strategy\Bulkhead\Storage\InMemoryStorage as BulkheadInMemoryStorage;
use Aegis\Strategy\Bulkhead\Storage\StorageInterface as BulkheadStorageInterface;
use Aegis\Strategy\Cache\CacheOptions;
use Aegis\Strategy\Cache\CacheStrategy;
use Aegis\Strategy\Cache\Storage\CacheInMemoryStorage;
use Aegis\Strategy\Cache\Storage\CacheStorageInterface;
use Aegis\Strategy\CircuitBreaker\AdaptiveCircuitBreakerOptions;
use Aegis\Strategy\CircuitBreaker\AdaptiveCircuitBreakerStrategy;
use Aegis\Strategy\CircuitBreaker\CircuitBreakerOptions;
use Aegis\Strategy\CircuitBreaker\CircuitBreakerStrategy;
use Aegis\Strategy\CircuitBreaker\Storage\AdaptiveInMemoryStorage;
use Aegis\Strategy\CircuitBreaker\Storage\AdaptiveStorageInterface;
use Aegis\Strategy\CircuitBreaker\Storage\InMemoryStorage;
use Aegis\Strategy\CircuitBreaker\Storage\StorageInterface;
use Aegis\Strategy\Fallback\FallbackStrategy;
use Aegis\Strategy\Hedge\HedgeOptions;
use Aegis\Strategy\Hedge\HedgeStrategy;
use Aegis\Strategy\RateLimiter\RateLimiterOptions;
use Aegis\Strategy\RateLimiter\RateLimiterStrategy;
use Aegis\Strategy\RateLimiter\SlidingWindowRateLimiterStrategy;
use Aegis\Strategy\RateLimiter\Storage\InMemoryStorage as RateLimiterInMemoryStorage;
use Aegis\Strategy\RateLimiter\Storage\SlidingWindowInMemoryStorage;
use Aegis\Strategy\RateLimiter\Storage\SlidingWindowStorageInterface;
use Aegis\Strategy\RateLimiter\Storage\StorageInterface as RateLimiterStorageInterface;
use Aegis\Strategy\RateLimiter\Storage\TokenBucketInMemoryStorage;
use Aegis\Strategy\RateLimiter\Storage\TokenBucketStorageInterface;
use Aegis\Strategy\RateLimiter\TokenBucketOptions;
use Aegis\Strategy\RateLimiter\TokenBucketRateLimiterStrategy;
use Aegis\Strategy\Retry\RetryOptions;
use Aegis\Strategy\Retry\RetryStrategy;
use Aegis\Strategy\Timeout\TimeoutStrategy;

final class ResiliencePipelineBuilder
{
    /** @var list<StrategyInterface> */
    private array $strategies = [];

    private EventDispatcherInterface $dispatcher;

    public function __construct()
    {
        $this->dispatcher = new NullEventDispatcher();
    }

    public function withEventDispatcher(EventDispatcherInterface $dispatcher): static
    {
        $this->dispatcher = $dispatcher;
        return $this;
    }

    /**
     * Add a Retry strategy.
     *
     * @param int                             $maxAttempts
     * @param BackoffInterface|null           $backoff     Defaults to ExponentialBackoff with jitter (100ms base).
     * @param array<class-string<\Throwable>> $retryOn
     * @param (callable(\Throwable): bool)|null $retryIf
     */
    public function retry(
        int               $maxAttempts = 3,
        ?BackoffInterface $backoff = null,
        array             $retryOn = [\Throwable::class],
        mixed             $retryIf = null,
    ): static {
        $backoff ??= ExponentialBackoff::withJitter(Duration::milliseconds(100));
        $options = new RetryOptions($maxAttempts, $backoff, $retryOn, $retryIf);
        $this->strategies[] = new RetryStrategy($options, $this->dispatcher);
        return $this;
    }

    /**
     * Add a Circuit Breaker strategy (consecutive-failure count).
     *
     * @param array<class-string<\Throwable>> $ignoreExceptions
     */
    public function circuitBreaker(
        string            $name,
        int               $failureThreshold = 5,
        int               $successThreshold = 2,
        ?Duration         $resetAfter = null,
        array             $ignoreExceptions = [],
        ?StorageInterface $storage = null,
    ): static {
        $options = new CircuitBreakerOptions($name, $failureThreshold, $successThreshold, $resetAfter, $ignoreExceptions);
        $storage ??= new InMemoryStorage();
        $this->strategies[] = new CircuitBreakerStrategy($options, $storage, $this->dispatcher);
        return $this;
    }

    /**
     * Add an Adaptive Circuit Breaker strategy (sliding-window failure rate).
     *
     * @param array<class-string<\Throwable>> $ignoreExceptions
     */
    public function adaptiveCircuitBreaker(
        string                   $name,
        int                      $windowSize = 20,
        float                    $failureRateThreshold = 50.0,
        int                      $minimumCalls = 5,
        int                      $successThreshold = 2,
        ?Duration                $resetAfter = null,
        array                    $ignoreExceptions = [],
        ?AdaptiveStorageInterface $storage = null,
    ): static {
        $options = new AdaptiveCircuitBreakerOptions(
            $name, $windowSize, $failureRateThreshold, $minimumCalls, $successThreshold, $resetAfter, $ignoreExceptions,
        );
        $storage ??= new AdaptiveInMemoryStorage();
        $this->strategies[] = new AdaptiveCircuitBreakerStrategy($options, $storage, $this->dispatcher);
        return $this;
    }

    /**
     * Add a Timeout strategy.
     * On Unix (pcntl): preemptive signal-based timeout.
     * On Windows: post-execution elapsed-time check.
     */
    public function timeout(Duration $duration): static
    {
        $this->strategies[] = new TimeoutStrategy($duration);
        return $this;
    }

    /**
     * Add a Rate Limiter strategy (Fixed Window).
     *
     * @param string                       $name    Unique identifier.
     * @param int                          $limit   Maximum calls per window.
     * @param Duration|null                $window  Window length. Defaults to 60 seconds.
     * @param RateLimiterStorageInterface|null $storage Defaults to InMemoryStorage.
     */
    public function rateLimit(
        string                       $name,
        int                          $limit = 100,
        ?Duration                    $window = null,
        ?RateLimiterStorageInterface $storage = null,
    ): static {
        $options = new RateLimiterOptions($name, $limit, $window);
        $storage ??= new RateLimiterInMemoryStorage();
        $this->strategies[] = new RateLimiterStrategy($options, $storage, $this->dispatcher);
        return $this;
    }

    /**
     * Add a Rate Limiter strategy (Sliding Window).
     *
     * @param string                          $name    Unique identifier.
     * @param int                             $limit   Maximum calls per window.
     * @param Duration|null                   $window  Window length. Defaults to 60 seconds.
     * @param SlidingWindowStorageInterface|null $storage Defaults to InMemoryStorage.
     */
    public function slidingRateLimit(
        string                          $name,
        int                             $limit = 100,
        ?Duration                       $window = null,
        ?SlidingWindowStorageInterface  $storage = null,
    ): static {
        $options = new RateLimiterOptions($name, $limit, $window);
        $storage ??= new SlidingWindowInMemoryStorage();
        $this->strategies[] = new SlidingWindowRateLimiterStrategy($options, $storage, $this->dispatcher);
        return $this;
    }

    /**
     * Add a Token Bucket Rate Limiter strategy.
     *
     * @param string                        $name         Unique identifier.
     * @param int                           $capacity     Maximum burst size.
     * @param int                           $refillRate   Tokens added per refill period.
     * @param Duration|null                 $refillPeriod Refill interval. Defaults to 1 second.
     * @param TokenBucketStorageInterface|null $storage   Defaults to InMemoryStorage.
     */
    public function tokenBucketRateLimit(
        string                        $name,
        int                           $capacity = 10,
        int                           $refillRate = 10,
        ?Duration                     $refillPeriod = null,
        ?TokenBucketStorageInterface  $storage = null,
    ): static {
        $options = new TokenBucketOptions($name, $capacity, $refillRate, $refillPeriod ?? Duration::seconds(1));
        $storage ??= new TokenBucketInMemoryStorage();
        $this->strategies[] = new TokenBucketRateLimiterStrategy($options, $storage, $this->dispatcher);
        return $this;
    }

    /**
     * Add a Bulkhead strategy (concurrency limiter).
     *
     * @param string                      $name          Unique identifier.
     * @param int                         $maxConcurrent Maximum concurrent executions. Defaults to 10.
     * @param BulkheadStorageInterface|null $storage      Defaults to InMemoryStorage.
     */
    public function bulkhead(
        string                    $name,
        int                       $maxConcurrent = 10,
        ?BulkheadStorageInterface $storage = null,
    ): static {
        $options = new BulkheadOptions($name, $maxConcurrent);
        $storage ??= new BulkheadInMemoryStorage();
        $this->strategies[] = new BulkheadStrategy($options, $storage, $this->dispatcher);
        return $this;
    }

    /**
     * Add a Fallback strategy.
     *
     * @param callable(\Throwable): mixed $handler Called with the caught exception; its return value is used as the result.
     */
    public function fallback(callable $handler): static
    {
        $this->strategies[] = new FallbackStrategy($handler);
        return $this;
    }

    /**
     * Add a Hedge strategy (experimental).
     *
     * Fires additional requests after `delay` if no result yet, and returns
     * the first success. Requires Fiber-cooperative callables for true
     * latency hedging; for synchronous callables, hedges fire on failure.
     *
     * @param Duration $delay      Time to wait before spawning a hedge. Use Duration::milliseconds(0) for immediate.
     * @param int      $maxHedges  Maximum additional attempts. Defaults to 1.
     */
    public function hedge(Duration $delay, int $maxHedges = 1): static
    {
        $this->strategies[] = new HedgeStrategy(new HedgeOptions($delay, $maxHedges));
        return $this;
    }

    /**
     * Add a Cache strategy.
     *
     * Caches successful results and returns cached values on subsequent calls.
     * With `staleOnFailure: true`, serves expired cache entries when `$next` throws.
     *
     * @param string              $name           Unique cache key.
     * @param Duration            $ttl            Cache entry lifetime.
     * @param bool                $staleOnFailure Serve stale cache on downstream failure.
     * @param CacheStorageInterface|null $storage Defaults to InMemoryStorage.
     */
    public function cache(
        string               $name,
        Duration             $ttl,
        bool                 $staleOnFailure = false,
        ?CacheStorageInterface $storage = null,
    ): static {
        $storage ??= new CacheInMemoryStorage();
        $options = new CacheOptions($name, $storage, $ttl, $staleOnFailure);
        $this->strategies[] = new CacheStrategy($options);
        return $this;
    }

    /** Add any custom strategy. */
    public function addStrategy(StrategyInterface $strategy): static
    {
        $this->strategies[] = $strategy;
        return $this;
    }

    public function build(): ResiliencePipeline
    {
        return ResiliencePipeline::fromStrategies($this->strategies);
    }
}
