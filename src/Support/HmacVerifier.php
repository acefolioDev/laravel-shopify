<?php

namespace LaravelShopify\Support;

use Illuminate\Http\Request;

/**
 * HMAC verification utilities for Shopify requests.
 *
 * Verifies HMAC signatures on:
 * - Webhook payloads (X-Shopify-Hmac-Sha256 header)
 * - App proxy requests (signature query parameter)
 * - OAuth callbacks (hmac query parameter)
 */
class HmacVerifier
{
    /**
     * Verify a webhook HMAC signature.
     */
    public static function verifyWebhook(string $body, string $hmacHeader, ?string $secret = null): bool
    {
        $secret = $secret ?? config('shopify-app.api_secret');

        if (empty($secret) || empty($hmacHeader)) {
            return false;
        }

        $calculated = base64_encode(hash_hmac('sha256', $body, $secret, true));

        return hash_equals($calculated, $hmacHeader);
    }

    /**
     * Verify an app proxy request signature.
     *
     * App proxy requests include a `signature` query parameter computed
     * from all other query parameters.
     */
    public static function verifyProxy(Request $request, ?string $secret = null): bool
    {
        $secret = $secret ?? config('shopify-app.api_secret');
        $params = $request->query();

        if (! isset($params['signature'])) {
            return false;
        }

        $signature = $params['signature'];
        unset($params['signature']);

        ksort($params);

        $parts = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            $parts[] = "{$key}={$value}";
        }

        $computed = hash_hmac('sha256', implode('', $parts), $secret);

        return hash_equals($computed, $signature);
    }

    /**
     * Verify an OAuth callback HMAC.
     */
    public static function verifyOAuth(array $queryParams, ?string $secret = null): bool
    {
        $secret = $secret ?? config('shopify-app.api_secret');

        if (! isset($queryParams['hmac'])) {
            return false;
        }

        $hmac = $queryParams['hmac'];
        unset($queryParams['hmac']);

        ksort($queryParams);

        $message = http_build_query($queryParams);
        $computed = hash_hmac('sha256', $message, $secret);

        return hash_equals($computed, $hmac);
    }

    /**
     * Validate that a shop domain looks legitimate.
     */
    public static function isValidShopDomain(string $domain): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $domain);
    }
}
