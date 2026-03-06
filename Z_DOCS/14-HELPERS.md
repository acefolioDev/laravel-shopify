# 14 - Helpers (ShopifyHelper & HmacVerifier)

The package provides two utility classes with static methods for common Shopify operations.

**Source files:** `src/Support/`

---

## 1. `ShopifyHelper` — Domain & URL Utilities

**File:** `src/Support/ShopifyHelper.php`

All methods are **static** — call them directly without instantiation.

### `sanitizeShopDomain(string $input): ?string`

Normalizes any shop domain input to the canonical `{name}.myshopify.com` format.

**Handles all these inputs:**

| Input | Output |
|---|---|
| `my-store` | `my-store.myshopify.com` |
| `my-store.myshopify.com` | `my-store.myshopify.com` |
| `https://my-store.myshopify.com` | `my-store.myshopify.com` |
| `https://my-store.myshopify.com/admin` | `my-store.myshopify.com` |
| `MY-STORE.myshopify.com` | `my-store.myshopify.com` |
| `my-store.myshopify.com:8080` | `my-store.myshopify.com` |
| ` ` (empty) | `null` |
| `-invalid` | `null` |

**Processing steps:**
1. Trim whitespace
2. Strip protocol (`https://`)
3. Strip path (`/admin/...`)
4. Strip port (`:8080`)
5. Append `.myshopify.com` if missing
6. Validate with `HmacVerifier::isValidShopDomain()`
7. Lowercase

**Usage:**
```php
use LaravelShopify\Support\ShopifyHelper;

$domain = ShopifyHelper::sanitizeShopDomain($request->query('shop'));
if (!$domain) {
    return response()->json(['error' => 'Invalid shop domain'], 400);
}
```

---

### `adminUrl(string $shopDomain, string $path = ''): string`

Builds a Shopify Admin URL.

```php
ShopifyHelper::adminUrl('my-store.myshopify.com');
// → "https://my-store.myshopify.com/admin"

ShopifyHelper::adminUrl('my-store.myshopify.com', 'products');
// → "https://my-store.myshopify.com/admin/products"

ShopifyHelper::adminUrl('my-store.myshopify.com', 'products/123');
// → "https://my-store.myshopify.com/admin/products/123"
```

Automatically sanitizes the input domain.

---

### `graphqlUrl(string $shopDomain, ?string $apiVersion = null): string`

Builds the GraphQL Admin API URL.

```php
ShopifyHelper::graphqlUrl('my-store.myshopify.com');
// → "https://my-store.myshopify.com/admin/api/2025-01/graphql.json"

ShopifyHelper::graphqlUrl('my-store.myshopify.com', '2024-10');
// → "https://my-store.myshopify.com/admin/api/2024-10/graphql.json"
```

Falls back to `config('shopify-app.api_version')` if no version specified.

---

### `restUrl(string $shopDomain, string $endpoint, ?string $apiVersion = null): string`

Builds a REST Admin API URL.

```php
ShopifyHelper::restUrl('my-store.myshopify.com', 'products.json');
// → "https://my-store.myshopify.com/admin/api/2025-01/products.json"
```

---

### `decodeHost(string $host): ?string`

Decodes Shopify's base64-encoded `host` query parameter.

```php
$host = 'YWRtaW4uc2hvcGlmeS5jb20vc3RvcmUvbXktc3RvcmU=';  // base64
ShopifyHelper::decodeHost($host);
// → "admin.shopify.com/store/my-store"
```

---

### `shopFromHost(string $host): ?string`

Extracts the shop domain from a base64-encoded `host` parameter. Handles both formats:

**New admin format:**
```php
$host = base64_encode('admin.shopify.com/store/my-store');
ShopifyHelper::shopFromHost($host);
// → "my-store.myshopify.com"
```

**Legacy format:**
```php
$host = base64_encode('my-store.myshopify.com/admin');
ShopifyHelper::shopFromHost($host);
// → "my-store.myshopify.com"
```

Returns `null` if the host format is unrecognized.

---

### `embeddedAppUrl(string $shopDomain, string $appPath = ''): string`

Builds the URL for your app within the Shopify Admin.

```php
ShopifyHelper::embeddedAppUrl('my-store.myshopify.com');
// → "https://admin.shopify.com/store/my-store/apps/{api_key}"

ShopifyHelper::embeddedAppUrl('my-store.myshopify.com', 'settings');
// → "https://admin.shopify.com/store/my-store/apps/{api_key}/settings"
```

Reads `api_key` from config. Used by `BillingController` to redirect merchants back to the app.

---

## 2. `HmacVerifier` — Security Verification

**File:** `src/Support/HmacVerifier.php`

Verifies HMAC signatures on requests from Shopify. All methods are **static**.

### `verifyWebhook(string $body, string $hmacHeader, ?string $secret = null): bool`

Verifies the HMAC signature on a webhook request.

**How Shopify signs webhooks:**
```
hmac = base64(hmac_sha256(request_body, api_secret))
```

**How verification works:**
```php
$calculated = base64_encode(hash_hmac('sha256', $body, $secret, true));
return hash_equals($calculated, $hmacHeader);
```

`hash_equals()` is used for **timing-safe** comparison to prevent timing attacks.

**Returns `false` if:**
- Secret is empty
- HMAC header is empty
- Signatures don't match

**Usage:**
```php
$hmac = $request->header('X-Shopify-Hmac-Sha256');
$body = $request->getContent();

if (!HmacVerifier::verifyWebhook($body, $hmac)) {
    abort(401, 'Invalid webhook signature');
}
```

---

### `verifyProxy(Request $request, ?string $secret = null): bool`

Verifies the `signature` query parameter on App Proxy requests.

**How Shopify signs proxy requests:**
1. Take all query parameters except `signature`
2. Sort them alphabetically by key
3. Join them as `key=value` (no separator between pairs)
4. Compute `hmac_sha256(joined_string, api_secret)` (hex, not base64)

**Example:**
```
Parameters: shop=my-store.myshopify.com, timestamp=1234567890, path_prefix=/apps/test
Sorted + joined: "path_prefix=/apps/testshop=my-store.myshopify.comtimestamp=1234567890"
Signature: hmac_sha256(joined, secret) → hex string
```

**Returns `false` if:**
- No `signature` query parameter present
- Signatures don't match

---

### `verifyOAuth(array $queryParams, ?string $secret = null): bool`

Verifies the `hmac` query parameter on OAuth callback requests.

**How Shopify signs OAuth callbacks:**
1. Take all query parameters except `hmac`
2. Sort them alphabetically
3. Build a standard query string: `code=abc&shop=my-store.myshopify.com&timestamp=123`
4. Compute `hmac_sha256(query_string, api_secret)` (hex)

**Note:** This is only used in legacy OAuth flow, not Token Exchange.

---

### `isValidShopDomain(string $domain): bool`

Validates that a domain string looks like a legitimate Shopify shop domain.

**Pattern:** `/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/`

```php
HmacVerifier::isValidShopDomain('my-store.myshopify.com');  // true
HmacVerifier::isValidShopDomain('store123.myshopify.com');  // true
HmacVerifier::isValidShopDomain('not-shopify.com');          // false
HmacVerifier::isValidShopDomain('-invalid.myshopify.com');   // false (starts with dash)
HmacVerifier::isValidShopDomain('');                          // false
```

This is used by `ShopifyHelper::sanitizeShopDomain()` as the final validation step.

---

## `ShopifyRequestContext` Trait

**File:** `src/Traits/ShopifyRequestContext.php`

A convenience trait for your controllers that provides helper methods to access the request context set by `VerifyShopify` middleware.

### Methods

| Method | Returns | Reads From |
|---|---|---|
| `getShopDomain($request)` | `?string` | `shopify_shop_domain` attribute |
| `getShopifySession($request)` | `?Session` | `shopify_session` attribute |
| `getAccessToken($request)` | `?string` | `shopify_access_token` attribute |
| `getSessionToken($request)` | `?object` | `shopify_session_token` attribute |
| `getShop($request)` | `?Shop` | Queries DB by domain |
| `getShopifyUserId($request)` | `?string` | `shopify_user_id` attribute |
| `appBridgeRedirect($url)` | `JsonResponse` | Creates response with `Link` header |

### Usage

```php
use LaravelShopify\Traits\ShopifyRequestContext;

class MyController extends Controller
{
    use ShopifyRequestContext;

    public function index(Request $request)
    {
        $shop = $this->getShopDomain($request);    // "my-store.myshopify.com"
        $token = $this->getAccessToken($request);   // "shpat_..."
        $session = $this->getShopifySession($request); // Session model
        $shopModel = $this->getShop($request);      // Shop model (DB query)
    }
}
```

### `appBridgeRedirect(string $url): JsonResponse`

Creates a response with the App Bridge redirect header for iframe breakout:

```php
return $this->appBridgeRedirect('https://some-shopify-page.com');
// Response:
// { "redirect_url": "https://some-shopify-page.com" }
// Link: <https://some-shopify-page.com>; rel="app-bridge-redirect-endpoint"
```

Used for billing redirects or any case where you need to break out of the Shopify Admin iframe.
