<?php

namespace LaravelShopify\Tests\Unit;

use LaravelShopify\Services\RateLimiter;
use LaravelShopify\Tests\TestCase;

class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::resetAll();
    }

    public function test_throttle_allows_requests_within_bucket(): void
    {
        $limiter = new RateLimiter(40, 2);

        $start = microtime(true);
        $limiter->throttle('test-shop.myshopify.com', 1);
        $elapsed = microtime(true) - $start;

        // Should not sleep for a single request
        $this->assertLessThan(0.1, $elapsed);
    }

    public function test_throttle_sleeps_when_bucket_full(): void
    {
        // Tiny bucket to force a sleep
        $limiter = new RateLimiter(2, 10);

        $limiter->throttle('test-shop.myshopify.com', 2);

        $start = microtime(true);
        $limiter->throttle('test-shop.myshopify.com', 1);
        $elapsed = microtime(true) - $start;

        // Should have slept briefly
        $this->assertGreaterThan(0.05, $elapsed);
    }

    public function test_update_from_response(): void
    {
        $limiter = new RateLimiter(1000, 50);

        // Fill up bucket
        $limiter->throttle('test-shop.myshopify.com', 500);

        // Simulate Shopify telling us 900 points are available
        $limiter->updateFromResponse('test-shop.myshopify.com', 900);

        // Should be able to make a big request without sleeping
        $start = microtime(true);
        $limiter->throttle('test-shop.myshopify.com', 50);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.1, $elapsed);
    }

    public function test_reset_clears_shop_bucket(): void
    {
        $limiter = new RateLimiter(2, 1);

        $limiter->throttle('test-shop.myshopify.com', 2);

        RateLimiter::reset('test-shop.myshopify.com');

        // After reset, should not sleep
        $start = microtime(true);
        $limiter->throttle('test-shop.myshopify.com', 1);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.1, $elapsed);
    }

    public function test_per_shop_isolation(): void
    {
        $limiter = new RateLimiter(2, 1);

        // Fill shop-a's bucket
        $limiter->throttle('shop-a.myshopify.com', 2);

        // shop-b should not be affected
        $start = microtime(true);
        $limiter->throttle('shop-b.myshopify.com', 1);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.1, $elapsed);
    }
}
