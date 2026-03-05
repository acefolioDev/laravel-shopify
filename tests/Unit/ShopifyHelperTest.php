<?php

namespace LaravelShopify\Tests\Unit;

use LaravelShopify\Support\ShopifyHelper;
use LaravelShopify\Tests\TestCase;

class ShopifyHelperTest extends TestCase
{
    public function test_sanitize_plain_name(): void
    {
        $this->assertEquals('my-store.myshopify.com', ShopifyHelper::sanitizeShopDomain('my-store'));
    }

    public function test_sanitize_full_domain(): void
    {
        $this->assertEquals('my-store.myshopify.com', ShopifyHelper::sanitizeShopDomain('my-store.myshopify.com'));
    }

    public function test_sanitize_with_protocol(): void
    {
        $this->assertEquals('my-store.myshopify.com', ShopifyHelper::sanitizeShopDomain('https://my-store.myshopify.com'));
    }

    public function test_sanitize_with_path(): void
    {
        $this->assertEquals('my-store.myshopify.com', ShopifyHelper::sanitizeShopDomain('https://my-store.myshopify.com/admin'));
    }

    public function test_sanitize_uppercase(): void
    {
        $this->assertEquals('my-store.myshopify.com', ShopifyHelper::sanitizeShopDomain('MY-STORE.myshopify.com'));
    }

    public function test_sanitize_empty_returns_null(): void
    {
        $this->assertNull(ShopifyHelper::sanitizeShopDomain(''));
    }

    public function test_sanitize_invalid_returns_null(): void
    {
        $this->assertNull(ShopifyHelper::sanitizeShopDomain('-invalid'));
    }

    public function test_admin_url(): void
    {
        $this->assertEquals(
            'https://my-store.myshopify.com/admin',
            ShopifyHelper::adminUrl('my-store.myshopify.com')
        );
    }

    public function test_admin_url_with_path(): void
    {
        $this->assertEquals(
            'https://my-store.myshopify.com/admin/products',
            ShopifyHelper::adminUrl('my-store.myshopify.com', 'products')
        );
    }

    public function test_graphql_url(): void
    {
        $this->assertEquals(
            'https://my-store.myshopify.com/admin/api/2025-01/graphql.json',
            ShopifyHelper::graphqlUrl('my-store.myshopify.com', '2025-01')
        );
    }

    public function test_rest_url(): void
    {
        $this->assertEquals(
            'https://my-store.myshopify.com/admin/api/2025-01/products.json',
            ShopifyHelper::restUrl('my-store.myshopify.com', 'products.json', '2025-01')
        );
    }

    public function test_decode_host(): void
    {
        $host = base64_encode('admin.shopify.com/store/my-store');

        $this->assertEquals('admin.shopify.com/store/my-store', ShopifyHelper::decodeHost($host));
    }

    public function test_shop_from_host_new_admin_format(): void
    {
        $host = base64_encode('admin.shopify.com/store/my-store');

        $this->assertEquals('my-store.myshopify.com', ShopifyHelper::shopFromHost($host));
    }

    public function test_shop_from_host_legacy_format(): void
    {
        $host = base64_encode('my-store.myshopify.com/admin');

        $this->assertEquals('my-store.myshopify.com', ShopifyHelper::shopFromHost($host));
    }

    public function test_shop_from_host_invalid(): void
    {
        $host = base64_encode('invalid-host');

        $this->assertNull(ShopifyHelper::shopFromHost($host));
    }

    public function test_embedded_app_url(): void
    {
        $url = ShopifyHelper::embeddedAppUrl('my-store.myshopify.com');

        $this->assertEquals(
            'https://admin.shopify.com/store/my-store/apps/test-api-key',
            $url
        );
    }

    public function test_embedded_app_url_with_path(): void
    {
        $url = ShopifyHelper::embeddedAppUrl('my-store.myshopify.com', 'settings');

        $this->assertEquals(
            'https://admin.shopify.com/store/my-store/apps/test-api-key/settings',
            $url
        );
    }
}
