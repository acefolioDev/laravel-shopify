<?php

namespace LaravelShopify\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use LaravelShopify\Events\ShopInstalled;
use LaravelShopify\Events\ShopTokenRefreshed;
use LaravelShopify\Exceptions\TokenExchangeException;
use LaravelShopify\Models\Session;
use LaravelShopify\Models\Shop;
use LaravelShopify\Services\WebhookRegistrar;

class TokenExchange
{
    private string $apiKey;
    private string $apiSecret;
    private string $scopes;
    private Client $httpClient;

    public function __construct(string $apiKey, string $apiSecret, string $scopes)
    {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->scopes = $scopes;
        $this->httpClient = new Client(['timeout' => 15]);
    }

    /**
     * Perform the Token Exchange: exchange a session token (JWT) for an access token.
     *
     * This replaces the legacy OAuth redirect flow. The frontend obtains a session
     * token from App Bridge and sends it to the backend, which exchanges it with
     * Shopify for an offline (or online) access token.
     *
     * @param string $shopDomain e.g. "my-store.myshopify.com"
     * @param string $sessionToken The JWT session token from App Bridge
     * @param bool $online Whether to request an online access token
     * @return Session The created or updated session
     * @throws TokenExchangeException
     */
    public function exchange(string $shopDomain, string $sessionToken, bool $online = false): Session
    {
        $tokenType = $online
            ? 'urn:ietf:params:oauth:token-type:access_token'
            : 'urn:ietf:params:oauth:token-type:offline_access_token';

        try {
            $response = $this->httpClient->post(
                "https://{$shopDomain}/admin/oauth/access_token",
                [
                    'json' => [
                        'client_id' => $this->apiKey,
                        'client_secret' => $this->apiSecret,
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                        'subject_token' => $sessionToken,
                        'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
                        'requested_token_type' => $tokenType,
                    ],
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            throw new TokenExchangeException(
                "Token exchange request failed for {$shopDomain}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }

        $body = json_decode($response->getBody()->getContents(), true);

        if (! isset($body['access_token'])) {
            throw new TokenExchangeException(
                'Token exchange response missing access_token for ' . $shopDomain
            );
        }

        return $this->persistSession($shopDomain, $body, $online);
    }

    /**
     * Refresh an offline access token using its refresh token.
     *
     * Shopify's expiring offline tokens include a refresh_token. This method
     * performs the refresh token rotation.
     *
     * @param string $shopDomain
     * @param string $refreshToken
     * @return Session
     * @throws TokenExchangeException
     */
    public function refresh(string $shopDomain, string $refreshToken): Session
    {
        try {
            $response = $this->httpClient->post(
                "https://{$shopDomain}/admin/oauth/access_token",
                [
                    'json' => [
                        'client_id' => $this->apiKey,
                        'client_secret' => $this->apiSecret,
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $refreshToken,
                    ],
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            throw new TokenExchangeException(
                "Token refresh failed for {$shopDomain}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }

        $body = json_decode($response->getBody()->getContents(), true);

        if (! isset($body['access_token'])) {
            throw new TokenExchangeException(
                'Token refresh response missing access_token for ' . $shopDomain
            );
        }

        return $this->persistSession($shopDomain, $body, false);
    }

    /**
     * Persist or update the session and shop records from the token response.
     */
    protected function persistSession(string $shopDomain, array $tokenData, bool $online): Session
    {
        $isNewInstall = ! Shop::where('shop_domain', $shopDomain)
            ->where('is_installed', true)
            ->exists();

        $shop = Shop::updateOrCreate(
            ['shop_domain' => $shopDomain],
            [
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'scopes' => $tokenData['scope'] ?? $this->scopes,
                'is_installed' => true,
                'installed_at' => now(),
                'token_expires_at' => isset($tokenData['expires_in'])
                    ? Carbon::now()->addSeconds($tokenData['expires_in'])
                    : null,
            ]
        );

        $sessionId = $online
            ? 'online_' . $shopDomain . '_' . ($tokenData['associated_user']['id'] ?? 'unknown')
            : 'offline_' . $shopDomain;

        $sessionData = [
            'shop_domain' => $shopDomain,
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'scope' => $tokenData['scope'] ?? $this->scopes,
            'is_online' => $online,
            'expires_at' => isset($tokenData['expires_in'])
                ? Carbon::now()->addSeconds($tokenData['expires_in'])
                : null,
        ];

        if ($online && isset($tokenData['associated_user'])) {
            $user = $tokenData['associated_user'];
            $sessionData['user_id'] = $user['id'] ?? null;
            $sessionData['user_first_name'] = $user['first_name'] ?? null;
            $sessionData['user_last_name'] = $user['last_name'] ?? null;
            $sessionData['user_email'] = $user['email'] ?? null;
            $sessionData['user_email_verified'] = $user['email_verified'] ?? null;
            $sessionData['account_owner'] = $user['account_owner'] ?? null;
            $sessionData['locale'] = $user['locale'] ?? null;
            $sessionData['collaborator'] = $user['collaborator'] ?? null;
            $sessionData['associated_user'] = $user;
        }

        $session = Session::updateOrCreate(
            ['session_id' => $sessionId],
            $sessionData
        );

        // Fire lifecycle events and register webhooks for new installs
        if ($isNewInstall && ! $online) {
            event(new ShopInstalled($shop));
            $this->registerWebhooks($shopDomain, $tokenData['access_token']);
        } elseif (! $isNewInstall && ! $online) {
            event(new ShopTokenRefreshed($shop));
        }

        return $session;
    }

    /**
     * Register all configured webhooks for a newly installed shop.
     */
    protected function registerWebhooks(string $shopDomain, string $accessToken): void
    {
        if (empty(config('shopify-app.webhooks', []))) {
            return;
        }

        try {
            $registrar = app(WebhookRegistrar::class);
            $registrar->registerAll($shopDomain, $accessToken);
        } catch (\Exception $e) {
            Log::warning('Failed to register webhooks during install', [
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ensure a valid offline session exists for the shop, performing token
     * exchange or refresh as needed.
     */
    public function ensureOfflineSession(string $shopDomain, string $sessionToken): Session
    {
        $session = Session::forShop($shopDomain)->offline()->valid()->first();

        if ($session && ! $session->isExpired()) {
            $shop = Shop::where('shop_domain', $shopDomain)->first();

            if ($shop && ! $shop->needsReauth()) {
                return $session;
            }
        }

        // Check if we can refresh
        if ($session && $session->refresh_token) {
            try {
                return $this->refresh($shopDomain, $session->refresh_token);
            } catch (TokenExchangeException $e) {
                // Fall through to full exchange
            }
        }

        return $this->exchange($shopDomain, $sessionToken, false);
    }
}
