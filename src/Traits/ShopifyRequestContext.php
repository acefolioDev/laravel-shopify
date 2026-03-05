<?php

namespace LaravelShopify\Traits;

use Illuminate\Http\Request;
use LaravelShopify\Models\Session;
use LaravelShopify\Models\Shop;

/**
 * Trait for controllers that need access to the Shopify request context.
 *
 * Use this in your app controllers after the VerifyShopify middleware
 * has run. It provides convenient accessors for the shop domain,
 * session, and access token set by the middleware.
 */
trait ShopifyRequestContext
{
    protected function getShopDomain(Request $request): ?string
    {
        return $request->attributes->get('shopify_shop_domain');
    }

    protected function getShopifySession(Request $request): ?Session
    {
        return $request->attributes->get('shopify_session');
    }

    protected function getAccessToken(Request $request): ?string
    {
        return $request->attributes->get('shopify_access_token');
    }

    protected function getSessionToken(Request $request): ?object
    {
        return $request->attributes->get('shopify_session_token');
    }

    protected function getShop(Request $request): ?Shop
    {
        $domain = $this->getShopDomain($request);

        if (! $domain) {
            return null;
        }

        return Shop::where('shop_domain', $domain)->first();
    }

    protected function getShopifyUserId(Request $request): ?string
    {
        return $request->attributes->get('shopify_user_id');
    }

    /**
     * Create a response with the App Bridge redirect header.
     * Used for billing redirects and reauth flows.
     */
    protected function appBridgeRedirect(string $url): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'redirect_url' => $url,
        ])->withHeaders([
            'Link' => '<' . $url . '>; rel="app-bridge-redirect-endpoint"',
        ]);
    }
}
