<?php

namespace LaravelShopify\Support;

/**
 * General-purpose Shopify helper utilities.
 */
class ShopifyHelper
{
    /**
     * Sanitize a shop domain to the canonical myshopify.com format.
     *
     * Handles inputs like:
     * - "my-store"
     * - "my-store.myshopify.com"
     * - "https://my-store.myshopify.com"
     * - "https://my-store.myshopify.com/admin"
     */
    public static function sanitizeShopDomain(string $input): ?string
    {
        $input = trim($input);

        // Strip protocol
        $input = preg_replace('#^https?://#', '', $input);

        // Strip path
        $input = explode('/', $input)[0];

        // Strip port
        $input = explode(':', $input)[0];

        if (empty($input)) {
            return null;
        }

        // If it doesn't end in .myshopify.com, append it
        if (! str_ends_with($input, '.myshopify.com')) {
            $input .= '.myshopify.com';
        }

        // Validate the final format
        if (! HmacVerifier::isValidShopDomain($input)) {
            return null;
        }

        return strtolower($input);
    }

    /**
     * Build the Shopify Admin URL for a given shop.
     */
    public static function adminUrl(string $shopDomain, string $path = ''): string
    {
        $shopDomain = self::sanitizeShopDomain($shopDomain) ?? $shopDomain;
        $path = ltrim($path, '/');

        return "https://{$shopDomain}/admin" . ($path ? "/{$path}" : '');
    }

    /**
     * Build the GraphQL Admin API URL for a given shop.
     */
    public static function graphqlUrl(string $shopDomain, ?string $apiVersion = null): string
    {
        $shopDomain = self::sanitizeShopDomain($shopDomain) ?? $shopDomain;
        $apiVersion = $apiVersion ?? config('shopify-app.api_version', '2025-01');

        return "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json";
    }

    /**
     * Build the REST Admin API URL for a given shop and endpoint.
     */
    public static function restUrl(string $shopDomain, string $endpoint, ?string $apiVersion = null): string
    {
        $shopDomain = self::sanitizeShopDomain($shopDomain) ?? $shopDomain;
        $apiVersion = $apiVersion ?? config('shopify-app.api_version', '2025-01');
        $endpoint = ltrim($endpoint, '/');

        return "https://{$shopDomain}/admin/api/{$apiVersion}/{$endpoint}";
    }

    /**
     * Decode a Shopify "host" parameter (base64-encoded).
     */
    public static function decodeHost(string $host): ?string
    {
        $decoded = base64_decode($host, true);

        return $decoded !== false ? $decoded : null;
    }

    /**
     * Extract the shop domain from a decoded host string.
     * Host format: "admin.shopify.com/store/{shop-name}" or "{shop}.myshopify.com/admin"
     */
    public static function shopFromHost(string $host): ?string
    {
        $decoded = self::decodeHost($host);

        if (! $decoded) {
            return null;
        }

        // New admin format: admin.shopify.com/store/{shop-name}
        if (preg_match('#admin\.shopify\.com/store/([a-zA-Z0-9\-]+)#', $decoded, $matches)) {
            return $matches[1] . '.myshopify.com';
        }

        // Legacy format: {shop}.myshopify.com/admin
        if (preg_match('#([a-zA-Z0-9\-]+\.myshopify\.com)#', $decoded, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Build the embedded app URL within the Shopify Admin.
     */
    public static function embeddedAppUrl(string $shopDomain, string $appPath = ''): string
    {
        $apiKey = config('shopify-app.api_key');
        $shopDomain = self::sanitizeShopDomain($shopDomain) ?? $shopDomain;
        $shopName = str_replace('.myshopify.com', '', $shopDomain);
        $appPath = ltrim($appPath, '/');

        return "https://admin.shopify.com/store/{$shopName}/apps/{$apiKey}"
            . ($appPath ? "/{$appPath}" : '');
    }
}
