<?php

namespace LaravelShopify\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use LaravelShopify\Support\HmacVerifier;

/**
 * Middleware to verify Shopify webhook HMAC signatures.
 *
 * Apply this to webhook routes instead of CSRF verification.
 * The webhook route should be excluded from the VerifyCsrfToken middleware.
 */
class VerifyWebhookHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        $hmac = $request->header('X-Shopify-Hmac-Sha256', '');
        $body = $request->getContent();

        if (! HmacVerifier::verifyWebhook($body, $hmac)) {
            return response()->json(['error' => 'Invalid HMAC signature'], 401);
        }

        return $next($request);
    }
}
