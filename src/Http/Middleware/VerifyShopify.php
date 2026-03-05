<?php

namespace LaravelShopify\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use LaravelShopify\Auth\SessionToken;
use LaravelShopify\Auth\TokenExchange;
use LaravelShopify\Exceptions\TokenExchangeException;
use UnexpectedValueException;

class VerifyShopify
{
    protected SessionToken $sessionToken;
    protected TokenExchange $tokenExchange;

    public function __construct(SessionToken $sessionTokenService = null, TokenExchange $tokenExchange = null)
    {
        $this->sessionToken = $sessionTokenService ?? new SessionToken(
            config('shopify-app.api_key'),
            config('shopify-app.api_secret'),
        );
        $this->tokenExchange = $tokenExchange ?? app(TokenExchange::class);
    }

    /**
     * Handle an incoming request.
     *
     * Validates the session token from the Authorization: Bearer header,
     * then ensures a valid offline session exists (performing token exchange
     * or refresh as needed).
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $rawToken = $this->sessionToken->extractFromRequest($request);

        if (! $rawToken) {
            return $this->unauthorizedResponse('Missing session token. Ensure the Authorization: Bearer header is set.');
        }

        try {
            $payload = $this->sessionToken->decode($rawToken);
        } catch (UnexpectedValueException $e) {
            return $this->unauthorizedResponse($e->getMessage());
        }

        $shopDomain = $this->sessionToken->getShopDomain($payload);

        $online = config('shopify-app.access_mode', 'offline') === 'online';

        try {
            $session = $this->tokenExchange->ensureSession($shopDomain, $rawToken, $online);
        } catch (TokenExchangeException $e) {
            return $this->unauthorizedResponse('Token exchange failed: ' . $e->getMessage());
        }

        // Bind context for downstream use
        $request->attributes->set('shopify_shop_domain', $shopDomain);
        $request->attributes->set('shopify_session', $session);
        $request->attributes->set('shopify_session_token', $payload);
        $request->attributes->set('shopify_access_token', $session->access_token);

        if ($this->sessionToken->isOnline($payload)) {
            $request->attributes->set('shopify_user_id', $payload->sub ?? null);
        }

        return $next($request);
    }

    protected function unauthorizedResponse(string $message): SymfonyResponse
    {
        return response()->json([
            'error' => 'Unauthorized',
            'message' => $message,
        ], 401);
    }
}
