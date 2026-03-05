<?php

namespace LaravelShopify\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelShopify\Tests\TestCase;

class ControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_endpoint_rejects_invalid_hmac(): void
    {
        $response = $this->postJson('/shopify/webhooks', ['id' => 123], [
            'X-Shopify-Hmac-Sha256' => 'invalid-hmac',
            'X-Shopify-Topic' => 'products/update',
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
        ]);

        $response->assertStatus(401);
    }

    public function test_webhook_endpoint_accepts_valid_hmac(): void
    {
        $body = json_encode(['id' => 123]);
        $secret = config('shopify-app.api_secret');
        $hmac = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $response = $this->call('POST', '/shopify/webhooks', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_TOPIC' => 'products/update',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'test-store.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertStatus(200);
    }

    public function test_token_exchange_rejects_missing_token(): void
    {
        $response = $this->postJson('/shopify/auth/token');

        $response->assertStatus(401);
        $response->assertJsonFragment(['error' => 'Missing session token.']);
    }

    public function test_billing_callback_rejects_missing_params(): void
    {
        $response = $this->getJson('/shopify/billing/callback');

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'Missing required parameters: shop, plan, charge_id.']);
    }

    public function test_billing_callback_with_all_params(): void
    {
        // This will fail at confirmCharge (no pending plan in DB) but proves routing works
        $response = $this->getJson('/shopify/billing/callback?shop=test.myshopify.com&plan=basic&charge_id=123');

        $response->assertStatus(500); // Expected — no pending plan exists
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }
}
