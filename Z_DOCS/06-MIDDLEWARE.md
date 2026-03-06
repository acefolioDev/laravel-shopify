# 06 - Middleware

The package provides 5 middleware classes. They are the gatekeepers that protect your routes.

**Source files:** `src/Http/Middleware/`

---

## Middleware Aliases

Registered by the service provider:

| Alias | Class | Purpose |
|---|---|---|
| `verify.shopify` | `VerifyShopify` | Validates JWT, performs token exchange, binds shop context |
| `verify.billing` | `VerifyBilling` | Checks for active billing plan |
| `verify.webhook.hmac` | `VerifyWebhookHmac` | Validates webhook HMAC signature |
| `verify.app.proxy` | `VerifyAppProxy` | Validates app proxy request signature |
| *(no alias)* | `ShareShopifyInertiaData` | Shares Shopify data with Inertia pages |

---

## 1. `VerifyShopify` — The Main Guard

**File:** `src/Http/Middleware/VerifyShopify.php`

This is the middleware you'll use on **every authenticated route**. It does three things:

### Step 1: Extract the JWT

```php
$rawToken = $this->sessionToken->extractFromRequest($request);
```

Looks for `Authorization: Bearer <token>` header, falls back to `id_token` query param. Returns **401** if missing.

### Step 2: Decode & Validate the JWT

```php
$payload = $this->sessionToken->decode($rawToken);
```

Verifies the signature, checks `iss`, `dest`, `aud`, `exp`, `nbf` claims. Returns **401** if invalid.

### Step 3: Ensure a Valid Session

```php
$session = $this->tokenExchange->ensureSession($shopDomain, $rawToken, $online);
```

Checks the database for a valid session. If none exists or it's expired, performs token exchange or refresh. Returns **401** if token exchange fails.

### Step 4: Bind Context to Request

After successful verification, these attributes are set on the request:

```php
$request->attributes->set('shopify_shop_domain', $shopDomain);    // "my-store.myshopify.com"
$request->attributes->set('shopify_session', $session);            // Session model
$request->attributes->set('shopify_session_token', $payload);      // Decoded JWT object
$request->attributes->set('shopify_access_token', $session->access_token); // "shpat_..."
$request->attributes->set('shopify_user_id', $payload->sub);      // Only for online tokens
```

### Usage

```php
Route::middleware('verify.shopify')->group(function () {
    Route::get('/api/products', [ProductController::class, 'index']);
    Route::post('/api/products', [ProductController::class, 'store']);
});
```

### Accessing Context in Controllers

Use the `ShopifyRequestContext` trait (see **14-HELPERS.md**) or read attributes directly:

```php
$shopDomain = $request->attributes->get('shopify_shop_domain');
$accessToken = $request->attributes->get('shopify_access_token');
```

### Error Response Format

```json
{
    "error": "Unauthorized",
    "message": "Missing session token. Ensure the Authorization: Bearer header is set."
}
```

Always returns HTTP **401**.

---

## 2. `VerifyBilling` — Billing Gatekeeper

**File:** `src/Http/Middleware/VerifyBilling.php`

Checks if the shop has an active billing plan. **Must run after `verify.shopify`** because it reads `shopify_shop_domain` from the request attributes.

### Logic Flow

1. If `billing.required` is `false` in config → **pass through** (billing disabled)
2. Read `shopify_shop_domain` from request → **403** if missing
3. Query `Plan::forShop($domain)->active()->first()`
4. If active plan exists:
   - If a specific plan slug was required and doesn't match → redirect to billing
   - Otherwise → set `shopify_plan` attribute and pass through
5. If no active plan → create a charge via `BillingService` and return **402** with redirect

### The 402 Response

When billing is required but no plan is active, the middleware returns:

```json
{
    "billing_required": true,
    "confirmation_url": "https://admin.shopify.com/store/my-store/charges/...",
    "plan": "basic"
}
```

With headers:
```
HTTP/1.1 402 Payment Required
Link: <https://checkout-url>; rel="app-bridge-redirect-endpoint"
X-Shopify-API-Request-Failure-Reauthorize: 1
X-Shopify-API-Request-Failure-Reauthorize-Url: https://checkout-url
```

The `Link` header with `rel="app-bridge-redirect-endpoint"` tells App Bridge 4 to break out of the iframe and redirect to the Shopify checkout page.

### Usage

```php
// Require any active plan
Route::middleware(['verify.shopify', 'verify.billing'])->group(function () {
    Route::get('/api/dashboard', [DashboardController::class, 'index']);
});

// Require a specific plan
Route::middleware(['verify.shopify', 'verify.billing:pro'])->group(function () {
    Route::get('/api/advanced', [AdvancedController::class, 'index']);
});
```

The `:pro` parameter is passed as `$planSlug` to the `handle()` method.

---

## 3. `VerifyWebhookHmac` — Webhook Security

**File:** `src/Http/Middleware/VerifyWebhookHmac.php`

Validates the HMAC signature on incoming Shopify webhooks.

### How It Works

1. Reads `X-Shopify-Hmac-Sha256` header
2. Gets the raw request body
3. Calls `HmacVerifier::verifyWebhook($body, $hmac)`
4. Returns **401** if invalid

### Usage

```php
Route::middleware('verify.webhook.hmac')->group(function () {
    Route::post('/my-webhook-endpoint', [MyController::class, 'handle']);
});
```

> **Note:** The package's built-in webhook route (`/shopify/webhooks`) does NOT use this middleware — the `WebhookController` does its own HMAC verification internally. This middleware is for custom webhook endpoints you create yourself.

---

## 4. `VerifyAppProxy` — App Proxy Security

**File:** `src/Http/Middleware/VerifyAppProxy.php`

Validates requests coming through a Shopify App Proxy.

### What Is an App Proxy?

An App Proxy lets you serve content on a merchant's storefront (e.g., `https://my-store.com/apps/my-app/...`). Shopify forwards these requests to your app and includes a `signature` query parameter.

### How It Works

1. Reads all query parameters
2. Calls `HmacVerifier::verifyProxy($request)`
3. Returns **401** if the signature is invalid

### Usage

```php
Route::middleware('verify.app.proxy')->group(function () {
    Route::any('/proxy/{path?}', [ProxyController::class, 'handle']);
});
```

---

## 5. `ShareShopifyInertiaData` — Inertia Integration

**File:** `src/Http/Middleware/ShareShopifyInertiaData.php`

**Not registered as an alias** — you add it manually to your middleware stack.

### What It Does

If Inertia.js is installed, shares Shopify-related data as props to every page:

```php
Inertia::share([
    'shopify' => [
        'apiKey' => config('shopify-app.api_key'),
        'appUrl' => config('shopify-app.app_url'),
        'shopDomain' => $request->attributes->get('shopify_shop_domain'),
        'host' => $request->query('host', ''),
    ],
]);
```

### Usage

Add it to your web middleware stack:

```php
// Laravel 10 — app/Http/Kernel.php
'web' => [
    // ... other middleware
    \LaravelShopify\Http\Middleware\ShareShopifyInertiaData::class,
],

// Laravel 11+ — bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \LaravelShopify\Http\Middleware\ShareShopifyInertiaData::class,
    ]);
})
```

Then in your React/Vue components:

```jsx
const { shopify } = usePage().props;
console.log(shopify.apiKey, shopify.shopDomain);
```

---

## Middleware Ordering

When stacking middleware, **order matters**:

```php
Route::middleware([
    'verify.shopify',      // FIRST — validates JWT, sets shop context
    'verify.billing',      // SECOND — reads shop context from verify.shopify
])->group(function () {
    // Your routes
});
```

`verify.billing` depends on `verify.shopify` running first because it reads `shopify_shop_domain` and `shopify_access_token` from the request attributes.
