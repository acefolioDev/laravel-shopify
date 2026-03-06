# 05 - Authentication (Token Exchange & Session Tokens)

This is the **most important part** of the package. Understanding authentication is key to understanding everything else.

**Source files:**
- `src/Auth/SessionToken.php` — JWT validation
- `src/Auth/TokenExchange.php` — Token exchange with Shopify

---

## The Big Picture

Shopify apps run **inside an iframe** in the Shopify Admin. The authentication flow works like this:

```
1. Merchant opens your app in Shopify Admin
2. App Bridge 4 (JavaScript) automatically loads
3. Your frontend calls shopify.idToken() → gets a JWT (session token)
4. Your frontend sends the JWT to your backend via Authorization: Bearer header
5. Your backend validates the JWT
6. Your backend exchanges the JWT with Shopify for an access token
7. Your backend stores the access token in the database
8. Your backend uses the access token to make API calls
```

**No redirects. No callback pages. No OAuth dance.**

---

## Part 1: Session Token (`SessionToken.php`)

The `SessionToken` class handles JWT validation. A session token is a JWT signed by your app's API secret.

### Construction

```php
$sessionToken = new SessionToken($apiKey, $apiSecret);
```

The service provider doesn't register `SessionToken` as a singleton — it's created inline in the `VerifyShopify` middleware and `TokenExchangeController`.

### `extractFromRequest(Request $request): ?string`

Extracts the raw JWT string from the request. Checks two places:

1. **`Authorization: Bearer <token>`** header (preferred)
2. **`id_token` query parameter** (fallback)

Bearer header takes precedence if both are present.

### `decode(string $token): object`

Decodes and validates the JWT:

1. **Signature verification** — Uses `firebase/php-jwt` with HS256 algorithm and your `api_secret` as the key
2. **Claim validation** via `validateClaims()`:
   - **`iss`** (issuer) — Must match `https://{shop}.myshopify.com/admin`
   - **`dest`** (destination) — Must match `https://{shop}.myshopify.com`
   - **`aud`** (audience) — Must match your `api_key`
   - **`exp`** (expiration) — Must not be in the past
   - **`nbf`** (not before) — Must not be more than 60 seconds in the future

Returns the decoded payload object with properties like `iss`, `dest`, `aud`, `exp`, `sub`, `sid`.

### `getShopDomain(object $payload): string`

Extracts the shop domain from the `dest` claim:
```
https://my-store.myshopify.com → my-store.myshopify.com
```

### `getSessionId(object $payload): string`

Generates a session ID used as the primary key in the sessions table:
- If `sid` claim exists → use it directly
- If `sub` (user ID) exists → `online_{shop}_{userId}`
- Otherwise → `offline_{shop}`

### `isOnline(object $payload): bool`

Returns `true` if the JWT has a `sub` (subject) claim, meaning it's tied to a specific user.

---

## Part 2: Token Exchange (`TokenExchange.php`)

The `TokenExchange` class is the **core of authentication**. It communicates with Shopify's token endpoint to exchange JWTs for access tokens.

### Construction

Registered as a singleton in the service provider:

```php
$this->app->singleton(TokenExchange::class, function ($app) {
    return new TokenExchange(
        config('shopify-app.api_key'),
        config('shopify-app.api_secret'),
        config('shopify-app.scopes'),
    );
});
```

### `exchange(string $shopDomain, string $sessionToken, bool $online = false): Session`

**The main method.** Exchanges a session token JWT for an access token.

**HTTP Request sent to Shopify:**
```
POST https://{shopDomain}/admin/oauth/access_token

{
    "client_id": "your-api-key",
    "client_secret": "your-api-secret",
    "grant_type": "urn:ietf:params:oauth:grant-type:token-exchange",
    "subject_token": "<the JWT>",
    "subject_token_type": "urn:ietf:params:oauth:token-type:id_token",
    "requested_token_type": "urn:shopify:params:oauth:token-type:offline-access-token"
}
```

The `requested_token_type` changes based on the `$online` parameter:
- **Offline:** `urn:shopify:params:oauth:token-type:offline-access-token`
- **Online:** `urn:shopify:params:oauth:token-type:online-access-token`

**Shopify responds with:**
```json
{
    "access_token": "shpat_...",
    "scope": "read_products,write_products",
    "expires_in": 86400,
    "refresh_token": "shprt_...",
    "associated_user": { ... }
}
```

The method then calls `persistSession()` to store everything.

### `refresh(string $shopDomain, string $refreshToken): Session`

Refreshes an expired offline token using the refresh token:

```
POST https://{shopDomain}/admin/oauth/access_token

{
    "client_id": "your-api-key",
    "client_secret": "your-api-secret",
    "grant_type": "refresh_token",
    "refresh_token": "shprt_..."
}
```

### `persistSession()` — The Heart of Data Storage

This private method does a lot:

1. **Checks if this is a new install** — Looks for an existing `Shop` with `is_installed = true`
2. **Upserts the `Shop` record** — `updateOrCreate` by `shop_domain`
3. **Upserts the `Session` record** — `updateOrCreate` by `session_id`
4. **Fires events:**
   - New install + offline → fires `ShopInstalled` event + registers webhooks
   - Existing install + offline → fires `ShopTokenRefreshed` event
5. **Stores online user data** if it's an online token (user name, email, etc.)

### `ensureOfflineSession(string $shopDomain, string $sessionToken): Session`

The **smart method** used by the middleware. Logic:

1. Look for an existing valid offline session in the database
2. If found and not expired, **and** the shop doesn't need reauth → return it (no HTTP call!)
3. If found with a refresh token → try to refresh
4. If refresh fails → do a full exchange
5. If no session exists → do a full exchange

This means **most requests don't trigger a token exchange** — they just read from the database.

### `ensureOnlineSession(string $shopDomain, string $sessionToken): Session`

Same as above but for online sessions. Doesn't attempt refresh (online tokens are short-lived).

### `ensureSession(string $shopDomain, string $sessionToken, bool $online = false): Session`

The top-level orchestrator. Tries the preferred mode, and if offline exchange is rejected (some apps don't support it), **automatically falls back to online mode**.

---

## How It All Fits Together

```
Request with Bearer JWT
        │
        ▼
VerifyShopify Middleware
        │
        ├── SessionToken.extractFromRequest() → raw JWT
        ├── SessionToken.decode() → validates JWT, gets payload
        ├── SessionToken.getShopDomain() → "my-store.myshopify.com"
        │
        ├── TokenExchange.ensureSession()
        │       │
        │       ├── Check DB for valid session → found? return it
        │       ├── Try refresh token → worked? return it
        │       └── Full exchange with Shopify → return new session
        │
        └── Bind to request attributes:
            ├── shopify_shop_domain
            ├── shopify_session
            ├── shopify_session_token
            ├── shopify_access_token
            └── shopify_user_id (if online)
```

---

## Key Design Decisions

1. **Offline tokens by default** — Offline tokens are long-lived and work for background jobs. Most apps should use offline mode.

2. **Automatic fallback** — If Shopify rejects offline token exchange, the package automatically tries online. This handles edge cases during migration.

3. **Webhook registration on install** — When a shop installs for the first time, webhooks are automatically registered. This happens inside `persistSession()`.

4. **Rate limiting is NOT applied to token exchange** — Token exchange uses a raw Guzzle client, not the rate-limited `GraphQLClient`. This is intentional — token exchange is a low-frequency operation.
