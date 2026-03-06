# 08 - Routes

The package registers 3 routes automatically. You do NOT need to define these yourself.

**Source file:** `routes/shopify.php`

---

## All Package Routes

All routes are under the `/shopify` prefix:

| Method | URI | Controller | Name | Purpose |
|---|---|---|---|---|
| `POST` | `/shopify/auth/token` | `TokenExchangeController@exchange` | `shopify.auth.token` | Exchange JWT for access token |
| `POST` | `/shopify/webhooks` | `WebhookController@handle` | `shopify.webhooks` | Receive all Shopify webhooks |
| `GET` | `/shopify/billing/callback` | `BillingController@callback` | `shopify.billing.callback` | Billing approval/decline callback |

---

## Route 1: Token Exchange — `POST /shopify/auth/token`

### Purpose

Called by the frontend to explicitly trigger a token exchange. The frontend sends the App Bridge session token and gets back a confirmation that the shop is authenticated.

### Request

```
POST /shopify/auth/token
Authorization: Bearer <session-token-jwt>
```

### Response (Success — 200)

```json
{
    "success": true,
    "shop": "my-store.myshopify.com",
    "session_id": "offline_my-store.myshopify.com",
    "expires_at": "2025-03-06T12:00:00.000000Z"
}
```

### Response (Error — 401)

```json
{
    "error": "Missing session token."
}
```

### When Is This Called?

In most cases, you **don't need to call this route directly**. The `verify.shopify` middleware handles token exchange automatically on every request. This route exists for:

- Explicit initial authentication on app load
- Frontend apps that want to confirm the session before making API calls

---

## Route 2: Webhooks — `POST /shopify/webhooks`

### Purpose

Receives ALL Shopify webhook POST requests. This is a single endpoint that dispatches to the correct Job class based on the webhook topic.

### How Shopify Sends Webhooks

Shopify includes these headers:

| Header | Example | Description |
|---|---|---|
| `X-Shopify-Hmac-Sha256` | `base64-encoded-hmac` | HMAC signature for verification |
| `X-Shopify-Topic` | `products/update` | The webhook topic (slash format) |
| `X-Shopify-Shop-Domain` | `my-store.myshopify.com` | The shop that triggered the webhook |
| `X-Shopify-API-Version` | `2025-01` | API version |

The body is a JSON payload with the resource data.

### How the Controller Processes It

1. **Verify HMAC** — Computes `base64(hmac_sha256(body, api_secret))` and compares with the header
2. **Normalize topic** — Converts `products/update` → `PRODUCTS_UPDATE` (to match config keys)
3. **Find Job class** — Looks up the topic in `config('shopify-app.webhooks')`
4. **Dispatch the Job** — `dispatch(new $jobClass($shopDomain, $data, $topic, $apiVersion))`

### Response Codes

| Code | When |
|---|---|
| 200 | HMAC valid, job dispatched (or no handler configured) |
| 401 | Invalid HMAC signature |
| 500 | Job class not found |

### CSRF Exemption

This route **must** be excluded from CSRF verification. See **02-INSTALLATION.md** for how.

---

## Route 3: Billing Callback — `GET /shopify/billing/callback`

### Purpose

After a merchant approves or declines a charge on Shopify's checkout page, Shopify redirects them back to this URL.

### Query Parameters

| Param | Description |
|---|---|
| `shop` | The shop domain |
| `plan` | The plan slug (matches config key) |
| `charge_id` | Shopify's charge ID |

### What Happens

1. Calls `BillingService::confirmCharge()` which:
   - Finds the pending plan in the database
   - Cancels any other active plans for the shop
   - Marks the plan as `active`
2. Redirects to the embedded app URL: `https://admin.shopify.com/store/{shop}/apps/{api_key}`

### The Redirect

After billing confirmation, the user is redirected **back into the Shopify Admin embedded app** using `ShopifyHelper::embeddedAppUrl()`. This ensures the merchant stays in the Shopify Admin context.

---

## Adding Your Own Routes

Your app's routes go in `routes/api.php` or `routes/web.php` as normal. Use the package middleware to protect them:

```php
// routes/api.php
Route::middleware('verify.shopify')->group(function () {
    Route::get('/api/products', [ProductController::class, 'index']);
    Route::post('/api/products', [ProductController::class, 'store']);
});

Route::middleware(['verify.shopify', 'verify.billing'])->group(function () {
    Route::get('/api/premium-feature', [PremiumController::class, 'index']);
});
```

---

## Viewing Registered Routes

```bash
php artisan route:list --name=shopify
```

This shows all routes with "shopify" in their name.
