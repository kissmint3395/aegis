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
use Aegis\Strategy\CircuitBreaker\CircuitBreakerOptions;
use Aegis\Strategy\CircuitBreaker\CircuitBreakerStrategy;
use Aegis\Strategy\CircuitBreaker\Storage\InMemoryStorage;
use Aegis\Strategy\CircuitBreaker\Storage\StorageInterface;
use Aegis\Strategy\RateLimiter\RateLimiterOptions;
use Aegis\Strategy\RateLimiter\RateLimiterStrategy;
use Aegis\Strategy\RateLimiter\Storage\InMemoryStorage as RateLimiterInMemoryStorage;
use Aegis\Strategy\RateLimiter\Storage\StorageInterface as RateLimiterStorageInterface;
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
        int             $maxAttempts = 3,
        ?BackoffInterface $backoff = null,
        array           $retryOn = [\Throwable::class],
        mixed           $retryIf = null,
    ): static {
        $backoff ??= ExponentialBackoff::withJitter(Duration::milliseconds(100));
        $options = new RetryOptions($maxAttempts, $backoff, $retryOn, $retryIf);
        $this->strategies[] = new RetryStrategy($options, $this->dispatcher);
        return $this;
    }

    /**
     * Add a Circuit Breaker strategy.
     *
     * @param array<class-string<\Throwable>> $ignoreExceptions
     */
    public function circuitBreaker(
        string           $name,
        int              $failureThreshold = 5,
        int              $successThreshold = 2,
        ?Duration        $resetAfter = null,
        array            $ignoreExceptions = [],
        ?StorageInterface $storage = null,
    ): static {
        $options = new CircuitBreakerOptions($name, $failureThreshold, $successThreshold, $resetAfter, $ignoreExceptions);
        $storage ??= new InMemoryStorage();
        $this->strategies[] = new CircuitBreakerStrategy($options, $storage, $this->dispatcher);
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
        string                      $name,
        int                         $limit = 100,
        ?Duration                   $window = null,
        ?RateLimiterStorageInterface $storage = null,
    ): static {
        $options = new RateLimiterOptions($name, $limit, $window);
        $storage ??= new RateLimiterInMemoryStorage();
        $this->strategies[] = new RateLimiterStrategy($options, $storage, $this->dispatcher);
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
