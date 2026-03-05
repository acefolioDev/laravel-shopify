<?php

namespace LaravelShopify\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LaravelShopify\Http\Middleware\VerifyBilling;
use LaravelShopify\Http\Middleware\VerifyShopify;
use LaravelShopify\Http\Middleware\VerifyWebhookHmac;
use LaravelShopify\Http\Middleware\VerifyAppProxy;
use LaravelShopify\Models\Plan;
use LaravelShopify\Models\Session;
use LaravelShopify\Models\Shop;
use LaravelShopify\Tests\TestCase;

class MiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_shopify_rejects_missing_token(): void
    {
        $request = Request::create('/api/test', 'GET');

        $middleware = app(VerifyShopify::class);
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Unauthorized', $data['error']);
    }

    public function test_verify_shopify_rejects_invalid_jwt(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer invalid.jwt.token');

        $middleware = app(VerifyShopify::class);
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_verify_webhook_hmac_accepts_valid(): void
    {
        $secret = config('shopify-app.api_secret');
        $body = '{"id":123}';
        $hmac = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $request = Request::create('/shopify/webhooks', 'POST', [], [], [], [], $body);
        $request->headers->set('X-Shopify-Hmac-Sha256', $hmac);

        $middleware = new VerifyWebhookHmac();
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_verify_webhook_hmac_rejects_invalid(): void
    {
        $request = Request::create('/shopify/webhooks', 'POST', [], [], [], [], '{"id":123}');
        $request->headers->set('X-Shopify-Hmac-Sha256', 'bad-hmac');

        $middleware = new VerifyWebhookHmac();
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_verify_billing_passes_when_not_required(): void
    {
        config()->set('shopify-app.billing.required', false);

        $request = Request::create('/api/test', 'GET');

        $middleware = app(VerifyBilling::class);
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_verify_billing_rejects_without_shop_domain(): void
    {
        config()->set('shopify-app.billing.required', true);

        $request = Request::create('/api/test', 'GET');
        // No shopify_shop_domain attribute set

        $middleware = app(VerifyBilling::class);
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_verify_billing_passes_with_active_plan(): void
    {
        config()->set('shopify-app.billing.required', true);

        Plan::create([
            'shop_domain' => 'test-store.myshopify.com',
            'plan_slug' => 'basic',
            'plan_name' => 'Basic Plan',
            'type' => 'recurring',
            'price' => 9.99,
            'status' => 'active',
        ]);

        $request = Request::create('/api/test', 'GET');
        $request->attributes->set('shopify_shop_domain', 'test-store.myshopify.com');
        $request->attributes->set('shopify_access_token', 'shpat_test');

        $middleware = app(VerifyBilling::class);
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertNotNull($request->attributes->get('shopify_plan'));
    }

    public function test_verify_app_proxy_rejects_invalid_signature(): void
    {
        $request = Request::create('/proxy?shop=test.myshopify.com&signature=bad', 'GET');

        $middleware = new VerifyAppProxy();
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(401, $response->getStatusCode());
    }
}
