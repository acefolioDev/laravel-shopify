# 03 - Configuration (`config/shopify-app.php`)

This file is the central configuration for the entire package. Every setting is explained below.

**Source file:** `config/shopify-app.php`

---

## App Credentials

```php
'api_key' => env('SHOPIFY_API_KEY', ''),
'api_secret' => env('SHOPIFY_API_SECRET', ''),
```

- **`api_key`** — Your app's Client ID from the Shopify Partners Dashboard. This is sent to Shopify during token exchange and is embedded in Blade layouts as a `<meta>` tag for App Bridge 4.
- **`api_secret`** — Your app's Client Secret. Used to:
  - Sign/verify JWTs (session tokens)
  - Verify webhook HMAC signatures
  - Verify app proxy signatures
  - Perform token exchange with Shopify

> **Security:** Never commit these values. Always use `.env`.

---

## Scopes

```php
'scopes' => env('SHOPIFY_SCOPES', 'read_products'),
```

Comma-separated string of Shopify API access scopes your app requests. Examples:
- `read_products,write_products` — Product management
- `read_orders,write_orders` — Order management
- `read_customers` — Customer data

The `Shop` model uses this to detect scope changes. If the configured scopes differ from what was granted, `Shop::needsReauth()` returns `true`, triggering a re-exchange.

**Full list:** [Shopify Access Scopes Reference](https://shopify.dev/docs/api/usage/access-scopes)

---

## App URL & Redirect URI

```php
'app_url' => env('SHOPIFY_APP_URL', env('APP_URL', 'https://localhost')),
'redirect_uri' => env('SHOPIFY_REDIRECT_URI', '/shopify/auth/callback'),
```

- **`app_url`** — Your app's public URL. Used for webhook callback URLs and billing return URLs. During development, this is your tunnel URL (set automatically by `shopify:app:dev`).
- **`redirect_uri`** — Legacy OAuth callback path. Not used in Token Exchange mode but kept for backward compatibility.

---

## API Version

```php
'api_version' => env('SHOPIFY_API_VERSION', '2025-01'),
```

The Shopify Admin API version used for all REST and GraphQL requests. Format: `YYYY-MM`. Shopify releases new versions quarterly.

**This is appended to all API URLs:**
```
https://{shop}.myshopify.com/admin/api/{api_version}/graphql.json
https://{shop}.myshopify.com/admin/api/{api_version}/products.json
```

---

## App Bridge

```php
'app_bridge' => [
    'enabled' => true,
    'cdn_url' => 'https://cdn.shopify.com/shopifycloud/app-bridge.js',
],
```

- **`enabled`** — Whether to inject the App Bridge CDN script in Blade layouts
- **`cdn_url`** — The CDN URL for App Bridge 4. You rarely need to change this.

---

## Authentication Mode

```php
'auth_mode' => env('SHOPIFY_AUTH_MODE', 'token_exchange'),
```

- **`token_exchange`** (recommended) — The 2026 standard. No redirects, no callback routes.
- **`authorization_code`** — Legacy OAuth flow. Only if token exchange isn't supported for your app.

---

## Access Mode

```php
'access_mode' => env('SHOPIFY_ACCESS_MODE', 'offline'),
```

- **`offline`** — Long-lived access tokens not tied to a user session. Used for background jobs, webhooks, and most app functionality. **This is the default and recommended mode.**
- **`online`** — Short-lived tokens tied to the currently logged-in Shopify admin user. Use when you need user-level permissions.

**How this affects the middleware:** The `VerifyShopify` middleware reads this config to decide whether to request offline or online tokens during token exchange.

---

## Session Storage

```php
'session' => [
    'driver' => 'eloquent',
    'table' => 'shopify_sessions',
    'expire_after' => 86400, // 24 hours
],
```

- **`driver`** — Currently only `eloquent` is supported (uses the `Session` model)
- **`table`** — The database table name
- **`expire_after`** — Default session lifetime in seconds

---

## Offline Token Expiry & Refresh

```php
'offline_tokens' => [
    'expiring' => true,
    'refresh_buffer_seconds' => 300, // 5 minutes
],
```

- **`expiring`** — Whether your app uses Shopify's expiring offline tokens. When `true`, the package tracks token expiry and auto-refreshes.
- **`refresh_buffer_seconds`** — How many seconds before expiry to proactively refresh the token. Default is 300 (5 minutes). This means if a token expires at 3:00 PM, it will be refreshed at 2:55 PM.

**How it works:** The `Shop::isTokenExpired()` method checks `token_expires_at - buffer_seconds`. The `VerifyShopify` middleware calls `TokenExchange::ensureSession()` which checks this and auto-refreshes if needed.

---

## Webhooks

```php
'webhooks' => [
    // 'APP_UNINSTALLED' => \App\Jobs\Shopify\AppUninstalledJob::class,
    // 'PRODUCTS_UPDATE' => \App\Jobs\Shopify\ProductsUpdateJob::class,
],

'webhook_path' => '/shopify/webhooks',
```

- **`webhooks`** — A map of topic → Job class. Topics use the GraphQL format (e.g., `APP_UNINSTALLED`, not `app/uninstalled`). The package auto-registers these with Shopify when a shop installs your app.
- **`webhook_path`** — The URL path where Shopify sends webhooks. Combined with `app_url` to form the full callback URL.

**The full callback URL sent to Shopify:** `{app_url}{webhook_path}` → e.g., `https://your-app.com/shopify/webhooks`

---

## Billing

```php
'billing' => [
    'enabled' => env('SHOPIFY_BILLING_ENABLED', false),
    'required' => env('SHOPIFY_BILLING_REQUIRED', false),

    'plans' => [
        // 'basic' => [
        //     'name' => 'Basic Plan',
        //     'type' => 'recurring',        // 'recurring' or 'one_time'
        //     'price' => 9.99,
        //     'currency' => 'USD',
        //     'interval' => 'EVERY_30_DAYS', // EVERY_30_DAYS or ANNUAL
        //     'trial_days' => 7,
        //     'test' => env('SHOPIFY_BILLING_TEST', true),
        //     'capped_amount' => null,       // for usage-based billing
        //     'terms' => null,
        // ],
    ],
],
```

- **`enabled`** — Master switch for billing features
- **`required`** — If `true`, the `VerifyBilling` middleware blocks requests from shops without an active plan
- **`plans`** — Define your pricing plans here. The key (e.g., `basic`) becomes the `plan_slug`

**Plan fields:**
| Field | Type | Description |
|---|---|---|
| `name` | string | Display name shown to merchant |
| `type` | string | `recurring` (subscription) or `one_time` |
| `price` | float | Price amount |
| `currency` | string | Currency code (default: `USD`) |
| `interval` | string | `EVERY_30_DAYS` or `ANNUAL` (recurring only) |
| `trial_days` | int | Free trial period |
| `test` | bool | `true` for development (no real charges) |
| `capped_amount` | float | Max usage charge (usage-based billing) |
| `terms` | string | Usage charge terms description |

---

## Tunnel

```php
'tunnel' => [
    'driver' => env('SHOPIFY_TUNNEL_DRIVER', 'ngrok'),
    'ngrok_auth_token' => env('NGROK_AUTH_TOKEN', ''),
    'cloudflare_bin' => env('CLOUDFLARE_BIN', 'cloudflared'),
    'port' => env('SHOPIFY_DEV_PORT', 8000),
],
```

Used by the `shopify:app:dev` Artisan command to create a tunnel for development.

- **`driver`** — `ngrok` or `cloudflare`
- **`ngrok_auth_token`** — Your ngrok auth token (get from [ngrok.com](https://ngrok.com))
- **`cloudflare_bin`** — Path to the `cloudflared` binary
- **`port`** — The port to tunnel (same as your Laravel dev server port)

---

## Partners Dashboard Auto-Update

```php
'partners' => [
    'auto_update' => env('SHOPIFY_PARTNERS_AUTO_UPDATE', false),
    'cli_token' => env('SHOPIFY_CLI_TOKEN', ''),
    'app_id' => env('SHOPIFY_APP_ID', ''),
],
```

If `auto_update` is `true`, the `shopify:app:dev` command automatically updates your app's URLs in the Shopify Partners Dashboard using the Partners API. This mirrors what `shopify app dev` does in the Node CLI.

---

## Rate Limiting (Leaky Bucket)

```php
'rate_limit' => [
    'rest' => [
        'bucket_size' => 40,
        'leak_rate' => 2,    // requests per second
    ],
    'graphql' => [
        'max_cost' => 1000,
        'restore_rate' => 50, // points per second
    ],
    'retry_after_seconds' => 1,
    'max_retries' => 3,
],
```

- **REST rate limit:** Shopify allows 40 requests in the bucket, leaking at 2/second
- **GraphQL rate limit:** 1000 cost points, restoring at 50/second
- **`retry_after_seconds`** — How long to wait after a 429 response
- **`max_retries`** — How many times to retry a throttled request

These values match Shopify's defaults. Only change them if Shopify changes their limits.

---

## Table Names

```php
'tables' => [
    'shops' => 'shopify_shops',
    'sessions' => 'shopify_sessions',
    'plans' => 'shopify_plans',
],
```

Customize the database table names if needed. Each model reads its table name from this config via `getTable()`.
