<?php

namespace LaravelShopify\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelShopify\Auth\SessionToken;
use LaravelShopify\Auth\TokenExchange;
use LaravelShopify\Exceptions\TokenExchangeException;

class TokenExchangeController extends Controller
{
    protected TokenExchange $tokenExchange;
    protected SessionToken $sessionToken;

    public function __construct(TokenExchange $tokenExchange)
    {
        $this->tokenExchange = $tokenExchange;
        $this->sessionToken = new SessionToken(
            config('shopify-app.api_key'),
            config('shopify-app.api_secret'),
        );
    }

    /**
     * Handle the token exchange request.
     *
     * The frontend sends the session token (JWT from App Bridge) and this
     * endpoint exchanges it for an offline access token.
     */
    public function exchange(Request $request): JsonResponse
    {
        $rawToken = $this->sessionToken->extractFromRequest($request);

        if (! $rawToken) {
            return response()->json([
                'error' => 'Missing session token.',
            ], 401);
        }

        try {
            $payload = $this->sessionToken->decode($rawToken);
        } catch (\UnexpectedValueException $e) {
            return response()->json([
                'error' => 'Invalid session token: ' . $e->getMessage(),
            ], 401);
        }

        $shopDomain = $this->sessionToken->getShopDomain($payload);

        try {
            $session = $this->tokenExchange->ensureOfflineSession($shopDomain, $rawToken);
        } catch (TokenExchangeException $e) {
            return response()->json([
                'error' => 'Token exchange failed: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'shop' => $shopDomain,
            'session_id' => $session->session_id,
            'expires_at' => $session->expires_at?->toISOString(),
        ]);
    }
}
