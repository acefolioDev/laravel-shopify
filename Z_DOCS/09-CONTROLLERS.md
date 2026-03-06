# 09 - Controllers

The package includes 3 controllers that handle the package's routes. You typically don't interact with these directly — they're internal to the package.

**Source files:** `src/Http/Controllers/`

---

## 1. `TokenExchangeController`

**File:** `src/Http/Controllers/TokenExchangeController.php`
**Route:** `POST /shopify/auth/token`

### Purpose

Provides an explicit endpoint for the frontend to trigger token exchange. While the `verify.shopify` middleware does this automatically, this controller gives the frontend a way to:
- Confirm the session is established
- Get the session ID and expiration

### Dependencies

Injected via constructor:
- `TokenExchange` — singleton from the container
- `SessionToken` — created inline with `api_key` and `api_secret` from config

### `exchange(Request $request): JsonResponse`

**Flow:**

```
1. Extract JWT from request (Bearer header or id_token query param)
   → 401 if missing

2. Decode and validate the JWT
   → 401 if invalid (expired, wrong audience, bad signature)

3. Get shop domain from JWT payload

4. Call $this->tokenExchange->ensureOfflineSession($shopDomain, $rawToken)
   → 500 if token exchange fails

5. Return success response with shop, session_id, expires_at
```

**Key detail:** This controller always requests an **offline** session, regardless of the `access_mode` config. The `verify.shopify` middleware respects the config, but this explicit endpoint is specifically for establishing the offline session.

---

## 2. `WebhookController`

**File:** `src/Http/Controllers/WebhookController.php`
**Route:** `POST /shopify/webhooks`

### Purpose

Receives all incoming Shopify webhooks and dispatches them to the configured Job class.

### No Dependency Injection

This controller doesn't inject any services — it reads config directly and performs HMAC verification inline.

### `handle(Request $request): JsonResponse`

**Flow:**

```
1. Read headers:
   - X-Shopify-Hmac-Sha256 → HMAC signature
   - X-Shopify-Topic → e.g., "products/update"
   - X-Shopify-Shop-Domain → e.g., "my-store.myshopify.com"
   - X-Shopify-API-Version → e.g., "2025-01"

2. Get raw body → $request->getContent()

3. Verify HMAC → base64(hmac_sha256(body, api_secret))
   → 401 if invalid

4. Normalize topic:
   "products/update" → "PRODUCTS_UPDATE"
   (Shopify sends slash format, config uses underscore format)

5. Look up Job class in config('shopify-app.webhooks')
   → 200 "No handler configured" if not found (not an error)
   → 500 if class doesn't exist

6. Dispatch job:
   dispatch(new $jobClass($shopDomain, $data, $topic, $apiVersion))

7. Return 200 "Webhook processed"
```

### Topic Normalization

The controller checks both formats:
```php
$webhooks[$normalizedTopic] ?? $webhooks[$topic] ?? null;
```

This means your config can use either `PRODUCTS_UPDATE` or `products/update` as the key, though the convention is uppercase with underscores.

### The `verifyHmac()` Method

The controller has its own private HMAC verification method (separate from the `VerifyWebhookHmac` middleware):

```php
$calculatedHmac = base64_encode(hash_hmac('sha256', $body, $secret, true));
return hash_equals($calculatedHmac, $hmacHeader);
```

This uses `hash_equals()` for timing-safe comparison to prevent timing attacks.

---

## 3. `BillingController`

**File:** `src/Http/Controllers/BillingController.php`
**Route:** `GET /shopify/billing/callback`

### Purpose

Handles the redirect from Shopify after a merchant approves or declines a billing charge.

### Dependencies

Injected via constructor:
- `BillingService` — singleton from the container

### `callback(Request $request): RedirectResponse`

**Flow:**

```
1. Read query parameters: shop, plan, charge_id

2. If all present:
   → Call $this->billing->confirmCharge($shopDomain, $planSlug, $chargeId)
   → On failure: log error but don't crash

3. Build embedded app URL via ShopifyHelper::embeddedAppUrl($shopDomain)

4. Redirect to the embedded app URL
```

### Why It Always Redirects

Even if billing confirmation fails, the controller still redirects the merchant back to the app. This is intentional — the merchant shouldn't be left on a blank page. The billing error is logged, and the `VerifyBilling` middleware will catch the missing plan on the next request.

### The Redirect URL

```php
ShopifyHelper::embeddedAppUrl($shopDomain)
// → "https://admin.shopify.com/store/my-store/apps/{api_key}"
```

This takes the merchant back to your app within the Shopify Admin.

---

## Creating Your Own Controllers

When building your app's controllers, use the `ShopifyRequestContext` trait to access shop data:

```php
use LaravelShopify\Traits\ShopifyRequestContext;

class ProductController extends Controller
{
    use ShopifyRequestContext;

    public function index(Request $request)
    {
        $shopDomain = $this->getShopDomain($request);
        $accessToken = $this->getAccessToken($request);

        $graphql = app(GraphQLClient::class);
        $products = $graphql->query($shopDomain, $accessToken, '{
            products(first: 10) {
                edges { node { id title } }
            }
        }');

        return response()->json($products);
    }
}
```
