<?php

namespace LaravelShopify\Tests\Unit;

use Illuminate\Http\Request;
use LaravelShopify\Support\HmacVerifier;
use LaravelShopify\Tests\TestCase;

class HmacVerifierTest extends TestCase
{
    public function test_verify_webhook_with_valid_hmac(): void
    {
        $secret = 'test-api-secret';
        $body = '{"id":123,"title":"Test Product"}';
        $hmac = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $this->assertTrue(HmacVerifier::verifyWebhook($body, $hmac, $secret));
    }

    public function test_verify_webhook_with_invalid_hmac(): void
    {
        $body = '{"id":123}';
        $this->assertFalse(HmacVerifier::verifyWebhook($body, 'invalid-hmac', 'test-api-secret'));
    }

    public function test_verify_webhook_with_empty_secret(): void
    {
        $this->assertFalse(HmacVerifier::verifyWebhook('body', 'hmac', ''));
    }

    public function test_verify_webhook_with_empty_hmac(): void
    {
        $this->assertFalse(HmacVerifier::verifyWebhook('body', '', 'secret'));
    }

    public function test_verify_proxy_with_valid_signature(): void
    {
        $secret = 'test-api-secret';
        $params = [
            'shop' => 'test-store.myshopify.com',
            'timestamp' => '1234567890',
            'path_prefix' => '/apps/test',
        ];

        ksort($params);
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = "{$key}={$value}";
        }
        $signature = hash_hmac('sha256', implode('', $parts), $secret);

        $params['signature'] = $signature;

        $request = Request::create('/proxy?' . http_build_query($params), 'GET');

        $this->assertTrue(HmacVerifier::verifyProxy($request, $secret));
    }

    public function test_verify_proxy_without_signature(): void
    {
        $request = Request::create('/proxy?shop=test.myshopify.com', 'GET');
        $this->assertFalse(HmacVerifier::verifyProxy($request, 'secret'));
    }

    public function test_verify_oauth_with_valid_hmac(): void
    {
        $secret = 'test-api-secret';
        $params = [
            'code' => 'auth-code',
            'shop' => 'test-store.myshopify.com',
            'timestamp' => '1234567890',
        ];

        ksort($params);
        $message = http_build_query($params);
        $hmac = hash_hmac('sha256', $message, $secret);

        $params['hmac'] = $hmac;

        $this->assertTrue(HmacVerifier::verifyOAuth($params, $secret));
    }

    public function test_verify_oauth_without_hmac(): void
    {
        $this->assertFalse(HmacVerifier::verifyOAuth(['shop' => 'test.myshopify.com'], 'secret'));
    }

    public function test_valid_shop_domains(): void
    {
        $this->assertTrue(HmacVerifier::isValidShopDomain('test-store.myshopify.com'));
        $this->assertTrue(HmacVerifier::isValidShopDomain('my-awesome-shop.myshopify.com'));
        $this->assertTrue(HmacVerifier::isValidShopDomain('store123.myshopify.com'));
    }

    public function test_invalid_shop_domains(): void
    {
        $this->assertFalse(HmacVerifier::isValidShopDomain(''));
        $this->assertFalse(HmacVerifier::isValidShopDomain('not-a-shopify-domain.com'));
        $this->assertFalse(HmacVerifier::isValidShopDomain('test.notshopify.com'));
        $this->assertFalse(HmacVerifier::isValidShopDomain('-invalid.myshopify.com'));
    }
}
