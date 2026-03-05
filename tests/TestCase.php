<?php

namespace LaravelShopify\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use LaravelShopify\ShopifyAppServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            ShopifyAppServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'ShopifyApp' => \LaravelShopify\Facades\ShopifyApp::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('shopify-app.api_key', 'test-api-key');
        $app['config']->set('shopify-app.api_secret', 'test-api-secret');
        $app['config']->set('shopify-app.scopes', 'read_products,write_products');
        $app['config']->set('shopify-app.app_url', 'https://test-app.example.com');
        $app['config']->set('shopify-app.api_version', '2025-01');
        $app['config']->set('shopify-app.billing.enabled', true);
        $app['config']->set('shopify-app.billing.required', false);
        $app['config']->set('shopify-app.billing.plans', [
            'basic' => [
                'name' => 'Basic Plan',
                'type' => 'recurring',
                'price' => 9.99,
                'currency' => 'USD',
                'interval' => 'EVERY_30_DAYS',
                'trial_days' => 7,
                'test' => true,
            ],
            'lifetime' => [
                'name' => 'Lifetime Access',
                'type' => 'one_time',
                'price' => 199.99,
                'currency' => 'USD',
                'test' => true,
            ],
        ]);
    }

    /**
     * Generate a valid session token JWT for testing.
     */
    protected function makeSessionToken(
        string $shop = 'test-store.myshopify.com',
        ?string $sub = null,
        ?int $exp = null,
        ?string $aud = null,
    ): string {
        $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

        $payload = [
            'iss' => "https://{$shop}/admin",
            'dest' => "https://{$shop}",
            'aud' => $aud ?? config('shopify-app.api_key', 'test-api-key'),
            'iat' => time(),
            'exp' => $exp ?? time() + 60,
            'nbf' => time() - 10,
            'jti' => bin2hex(random_bytes(16)),
        ];

        if ($sub) {
            $payload['sub'] = $sub;
            $payload['sid'] = "online_{$shop}_{$sub}";
        } else {
            $payload['sid'] = "offline_{$shop}";
        }

        $payloadEncoded = base64url_encode(json_encode($payload));
        $secret = config('shopify-app.api_secret', 'test-api-secret');
        $signature = base64url_encode(
            hash_hmac('sha256', "{$header}.{$payloadEncoded}", $secret, true)
        );

        return "{$header}.{$payloadEncoded}.{$signature}";
    }
}

/**
 * Base64 URL-safe encode (no padding).
 */
function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
