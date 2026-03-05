<?php

namespace LaravelShopify\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelShopify\Models\Plan;
use LaravelShopify\Models\Session;
use LaravelShopify\Models\Shop;
use LaravelShopify\Tests\TestCase;

class ModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_shop(): void
    {
        $shop = Shop::create([
            'shop_domain' => 'test-store.myshopify.com',
            'shop_name' => 'Test Store',
            'email' => 'owner@test-store.com',
            'access_token' => 'shpat_test_token',
            'scopes' => 'read_products,write_products',
            'is_installed' => true,
            'installed_at' => now(),
        ]);

        $this->assertDatabaseHas('shopify_shops', [
            'shop_domain' => 'test-store.myshopify.com',
            'is_installed' => true,
        ]);

        $this->assertEquals('test-store.myshopify.com', $shop->shop_domain);
        $this->assertTrue($shop->is_installed);
    }

    public function test_shop_needs_reauth_without_session(): void
    {
        $shop = Shop::create([
            'shop_domain' => 'test-store.myshopify.com',
            'is_installed' => true,
        ]);

        // No offline session exists — needsReauth should return true
        $this->assertTrue($shop->needsReauth());
    }

    public function test_shop_needs_reauth_with_expired_token(): void
    {
        $shop = Shop::create([
            'shop_domain' => 'test-store.myshopify.com',
            'scopes' => 'read_products,write_products',
            'is_installed' => true,
        ]);

        Session::create([
            'session_id' => 'offline_test-store.myshopify.com',
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'shpat_test',
            'is_online' => false,
            'expires_at' => now()->subHour(),
        ]);

        $this->assertTrue($shop->isTokenExpired());
        $this->assertTrue($shop->needsReauth());
    }

    public function test_shop_does_not_need_reauth_with_valid_token(): void
    {
        $shop = Shop::create([
            'shop_domain' => 'test-store.myshopify.com',
            'scopes' => 'read_products,write_products',
            'is_installed' => true,
        ]);

        Session::create([
            'session_id' => 'offline_test-store.myshopify.com',
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'shpat_test',
            'is_online' => false,
            'expires_at' => now()->addHour(),
        ]);

        $this->assertFalse($shop->isTokenExpired());
        $this->assertFalse($shop->needsReauth());
    }

    public function test_shop_needs_reauth_with_insufficient_scopes(): void
    {
        $shop = Shop::create([
            'shop_domain' => 'test-store.myshopify.com',
            'scopes' => 'read_products', // Missing write_products
            'is_installed' => true,
        ]);

        Session::create([
            'session_id' => 'offline_test-store.myshopify.com',
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'shpat_test',
            'is_online' => false,
        ]);

        $this->assertTrue($shop->needsReauth());
    }

    public function test_shop_has_sessions(): void
    {
        $shop = Shop::create([
            'shop_domain' => 'test-store.myshopify.com',
            'is_installed' => true,
        ]);

        Session::create([
            'session_id' => 'offline_test-store.myshopify.com',
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'shpat_offline',
            'is_online' => false,
        ]);

        Session::create([
            'session_id' => 'online_test-store.myshopify.com_123',
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'shpat_online',
            'is_online' => true,
            'user_id' => 123,
        ]);

        $this->assertCount(2, $shop->sessions);
        $this->assertNotNull($shop->offlineSession);
        $this->assertFalse($shop->offlineSession->is_online);
    }

    public function test_session_scopes(): void
    {
        Session::create([
            'session_id' => 'offline_test-store.myshopify.com',
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'shpat_valid',
            'is_online' => false,
            'expires_at' => now()->addHour(),
        ]);

        Session::create([
            'session_id' => 'offline_expired.myshopify.com',
            'shop_domain' => 'expired.myshopify.com',
            'access_token' => 'shpat_expired',
            'is_online' => false,
            'expires_at' => now()->subHour(),
        ]);

        $validSessions = Session::valid()->get();
        $this->assertCount(1, $validSessions);
        $this->assertEquals('test-store.myshopify.com', $validSessions->first()->shop_domain);

        $offlineSessions = Session::offline()->get();
        $this->assertCount(2, $offlineSessions);
    }

    public function test_session_is_valid(): void
    {
        $valid = new Session([
            'access_token' => 'token',
            'expires_at' => now()->addHour(),
        ]);
        $this->assertTrue($valid->isValid());

        $expired = new Session([
            'access_token' => 'token',
            'expires_at' => now()->subHour(),
        ]);
        $this->assertFalse($expired->isValid());

        $noToken = new Session([
            'access_token' => null,
            'expires_at' => now()->addHour(),
        ]);
        $this->assertFalse($noToken->isValid());
    }

    public function test_create_plan(): void
    {
        $plan = Plan::create([
            'shop_domain' => 'test-store.myshopify.com',
            'plan_slug' => 'basic',
            'plan_name' => 'Basic Plan',
            'type' => 'recurring',
            'price' => 9.99,
            'currency' => 'USD',
            'interval' => 'EVERY_30_DAYS',
            'trial_days' => 7,
            'test' => true,
            'status' => 'active',
            'activated_at' => now(),
            'trial_ends_at' => now()->addDays(7),
        ]);

        $this->assertTrue($plan->isActive());
        $this->assertTrue($plan->isRecurring());
        $this->assertFalse($plan->isOneTime());
        $this->assertTrue($plan->isInTrial());
    }

    public function test_plan_scopes(): void
    {
        Plan::create([
            'shop_domain' => 'test-store.myshopify.com',
            'plan_slug' => 'basic',
            'plan_name' => 'Basic',
            'type' => 'recurring',
            'price' => 9.99,
            'status' => 'active',
        ]);

        Plan::create([
            'shop_domain' => 'test-store.myshopify.com',
            'plan_slug' => 'old',
            'plan_name' => 'Old Plan',
            'type' => 'recurring',
            'price' => 4.99,
            'status' => 'cancelled',
        ]);

        $activePlans = Plan::forShop('test-store.myshopify.com')->active()->get();
        $this->assertCount(1, $activePlans);
        $this->assertEquals('basic', $activePlans->first()->plan_slug);
    }

    public function test_shop_has_active_plan(): void
    {
        Shop::create([
            'shop_domain' => 'test-store.myshopify.com',
            'is_installed' => true,
        ]);

        Plan::create([
            'shop_domain' => 'test-store.myshopify.com',
            'plan_slug' => 'basic',
            'plan_name' => 'Basic',
            'type' => 'recurring',
            'price' => 9.99,
            'status' => 'active',
        ]);

        $shop = Shop::where('shop_domain', 'test-store.myshopify.com')->first();
        $this->assertNotNull($shop->activePlan);
        $this->assertEquals('basic', $shop->activePlan->plan_slug);
    }

    public function test_installed_scope(): void
    {
        Shop::create(['shop_domain' => 'installed.myshopify.com', 'is_installed' => true]);
        Shop::create(['shop_domain' => 'uninstalled.myshopify.com', 'is_installed' => false]);

        $installed = Shop::installed()->get();
        $this->assertCount(1, $installed);
        $this->assertEquals('installed.myshopify.com', $installed->first()->shop_domain);
    }
}
