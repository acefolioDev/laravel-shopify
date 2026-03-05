<?php

namespace LaravelShopify\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use LaravelShopify\Support\HmacVerifier;

/**
 * Verifies requests coming through a Shopify App Proxy.
 *
 * App Proxy requests include a `signature` query parameter
 * that must be validated against the app's API secret.
 */
class VerifyAppProxy
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! HmacVerifier::verifyProxy($request)) {
            return response()->json(['error' => 'Invalid app proxy signature'], 401);
        }

        return $next($request);
    }
}
