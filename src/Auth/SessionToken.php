<?php

namespace LaravelShopify\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use UnexpectedValueException;

class SessionToken
{
    private string $apiKey;
    private string $apiSecret;

    public function __construct(string $apiKey, string $apiSecret)
    {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    /**
     * Extract the session token from the Authorization: Bearer header.
     */
    public function extractFromRequest(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $request->query('id_token');
    }

    /**
     * Decode and validate a Shopify session token (JWT).
     *
     * @return object The decoded JWT payload.
     * @throws UnexpectedValueException
     */
    public function decode(string $token): object
    {
        try {
            $decoded = JWT::decode($token, new Key($this->apiSecret, 'HS256'));
        } catch (\Exception $e) {
            throw new UnexpectedValueException('Invalid session token: ' . $e->getMessage());
        }

        $this->validateClaims($decoded);

        return $decoded;
    }

    /**
     * Validate the JWT claims per Shopify's requirements.
     */
    protected function validateClaims(object $payload): void
    {
        if (! isset($payload->iss)) {
            throw new UnexpectedValueException('Session token missing "iss" claim.');
        }

        $issuer = $payload->iss;
        if (! preg_match('#^https://[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com/admin$#', $issuer)) {
            throw new UnexpectedValueException('Session token "iss" claim is invalid.');
        }

        if (! isset($payload->dest)) {
            throw new UnexpectedValueException('Session token missing "dest" claim.');
        }

        if (! preg_match('#^https://[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$#', $payload->dest)) {
            throw new UnexpectedValueException('Session token "dest" claim is invalid.');
        }

        if (! isset($payload->aud) || $payload->aud !== $this->apiKey) {
            throw new UnexpectedValueException('Session token "aud" claim does not match the API key.');
        }

        if (isset($payload->exp) && $payload->exp < time()) {
            throw new UnexpectedValueException('Session token has expired.');
        }

        if (isset($payload->nbf) && $payload->nbf > time() + 60) {
            throw new UnexpectedValueException('Session token is not yet valid.');
        }
    }

    /**
     * Extract the shop domain from a decoded session token.
     */
    public function getShopDomain(object $payload): string
    {
        $dest = $payload->dest;

        return str_replace('https://', '', $dest);
    }

    /**
     * Extract the session ID from the JWT.
     * Format: offline_{shop} or online_{shop}_{user_id}
     */
    public function getSessionId(object $payload): string
    {
        if (isset($payload->sid)) {
            return $payload->sid;
        }

        $shop = $this->getShopDomain($payload);

        if (isset($payload->sub)) {
            return "online_{$shop}_{$payload->sub}";
        }

        return "offline_{$shop}";
    }

    /**
     * Check if this is an online session token (has associated user).
     */
    public function isOnline(object $payload): bool
    {
        return isset($payload->sub) && ! empty($payload->sub);
    }
}
