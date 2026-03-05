<?php

namespace LaravelShopify\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use LaravelShopify\Navigation\NavigationBridge;

/**
 * Shares Shopify-related data with Inertia pages as props.
 *
 * Use this middleware in your Inertia stack to automatically pass
 * the API key, shop domain, and navigation data to every page.
 */
class ShareShopifyInertiaData
{
    public function handle(Request $request, Closure $next): Response
    {
        if (class_exists(\Inertia\Inertia::class)) {
            \Inertia\Inertia::share([
                'shopify' => fn () => [
                    'apiKey' => config('shopify-app.api_key'),
                    'appUrl' => config('shopify-app.app_url'),
                    'shopDomain' => $request->attributes->get('shopify_shop_domain'),
                    'host' => $request->query('host', ''),
                ],
            ]);
        }

        return $next($request);
    }
}
