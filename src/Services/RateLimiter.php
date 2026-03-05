<?php

namespace LaravelShopify\Services;

/**
 * Leaky Bucket rate limiter for Shopify API calls.
 *
 * Tracks per-shop bucket state and sleeps when necessary to avoid 429s.
 */
class RateLimiter
{
    /** @var array<string, array{tokens: float, last_request: float}> */
    protected static array $buckets = [];

    protected int $bucketSize;
    protected float $leakRate;

    public function __construct(int $bucketSize, float $leakRate)
    {
        $this->bucketSize = $bucketSize;
        $this->leakRate = $leakRate;
    }

    /**
     * Wait if necessary before making a request, then consume a token.
     *
     * @param string $shopDomain The shop identifier for per-shop rate limiting
     * @param int $cost The cost of this request (1 for REST, variable for GraphQL)
     */
    public function throttle(string $shopDomain, int $cost = 1): void
    {
        $now = microtime(true);

        if (! isset(static::$buckets[$shopDomain])) {
            static::$buckets[$shopDomain] = [
                'tokens' => 0,
                'last_request' => $now,
            ];
        }

        $bucket = &static::$buckets[$shopDomain];

        // Leak tokens since last request
        $elapsed = $now - $bucket['last_request'];
        $leaked = $elapsed * $this->leakRate;
        $bucket['tokens'] = max(0, $bucket['tokens'] - $leaked);
        $bucket['last_request'] = $now;

        // If adding cost would exceed bucket, sleep until enough tokens leak
        if (($bucket['tokens'] + $cost) > $this->bucketSize) {
            $overflow = ($bucket['tokens'] + $cost) - $this->bucketSize;
            $sleepSeconds = $overflow / $this->leakRate;
            usleep((int) ($sleepSeconds * 1_000_000));

            // Recalculate after sleep
            $bucket['tokens'] = max(0, $bucket['tokens'] - ($sleepSeconds * $this->leakRate));
            $bucket['last_request'] = microtime(true);
        }

        $bucket['tokens'] += $cost;
    }

    /**
     * Handle a 429 response by sleeping for the indicated duration.
     */
    public function handleRetryAfter(string $shopDomain, float $retryAfterSeconds): void
    {
        usleep((int) ($retryAfterSeconds * 1_000_000));

        // Reset bucket after waiting
        if (isset(static::$buckets[$shopDomain])) {
            static::$buckets[$shopDomain]['tokens'] = 0;
            static::$buckets[$shopDomain]['last_request'] = microtime(true);
        }
    }

    /**
     * Update the bucket state from response headers (for GraphQL throttle status).
     */
    public function updateFromResponse(string $shopDomain, int $currentlyAvailable): void
    {
        if (isset(static::$buckets[$shopDomain])) {
            static::$buckets[$shopDomain]['tokens'] = $this->bucketSize - $currentlyAvailable;
            static::$buckets[$shopDomain]['last_request'] = microtime(true);
        }
    }

    /**
     * Reset the bucket for a specific shop.
     */
    public static function reset(string $shopDomain): void
    {
        unset(static::$buckets[$shopDomain]);
    }

    /**
     * Reset all buckets.
     */
    public static function resetAll(): void
    {
        static::$buckets = [];
    }
}
