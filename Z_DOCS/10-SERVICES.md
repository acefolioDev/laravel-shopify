# 10 - Services (API Clients & Rate Limiter)

The package provides service classes for communicating with Shopify's APIs. All are registered as singletons in the service container.

**Source files:** `src/Services/`

---

## 1. `GraphQLClient` — Shopify Admin GraphQL API

**File:** `src/Services/GraphQLClient.php`

The primary way to interact with Shopify's Admin API. GraphQL is Shopify's recommended API for all new development.

### Registration

```php
// Singleton in ShopifyAppServiceProvider
$this->app->singleton(GraphQLClient::class, function ($app) {
    return new GraphQLClient(
        config('shopify-app.api_version'),        // e.g., "2025-01"
        config('shopify-app.rate_limit.graphql'),  // ['max_cost' => 1000, 'restore_rate' => 50]
    );
});
```

### Constructor

Internally creates:
- A `GuzzleHttp\Client` with 30-second timeout
- A `RateLimiter` configured with the GraphQL bucket parameters
- Reads `max_retries` and `retry_after_seconds` from config

### `query()` Method

```php
public function query(
    string $shopDomain,
    string $accessToken,
    string $query,
    array $variables = [],
    int $cost = 10
): array
```

**Parameters:**
- `$shopDomain` — e.g., `my-store.myshopify.com`
- `$accessToken` — The access token from the session
- `$query` — GraphQL query or mutation string
- `$variables` — GraphQL variables (optional)
- `$cost` — Estimated query cost for rate limiting (default 10)

**What it does:**

1. **Rate limit check** — `$this->rateLimiter->throttle($shopDomain, $cost)` — sleeps if bucket is full
2. **Send request** to `https://{shop}/admin/api/{version}/graphql.json` with `X-Shopify-Access-Token` header
3. **Update rate limiter** from response's `extensions.cost.throttleStatus.currentlyAvailable`
4. **Handle throttling** — If response contains "Throttled" error or HTTP 429, waits and retries (up to `max_retries`)
5. **Return** the `data` key from the response

**Usage:**

```php
$graphql = app(GraphQLClient::class);

// Query
$result = $graphql->query($shopDomain, $accessToken, '
    {
        products(first: 10) {
            edges {
                node { id title }
            }
        }
    }
');
// $result = ['products' => ['edges' => [...]]]

// Query with variables
$result = $graphql->query($shopDomain, $accessToken, '
    query getProduct($id: ID!) {
        product(id: $id) {
            id title description
        }
    }
', ['id' => 'gid://shopify/Product/123']);

// Mutation
$result = $graphql->mutate($shopDomain, $accessToken, '
    mutation productCreate($input: ProductInput!) {
        productCreate(input: $input) {
            product { id title }
            userErrors { field message }
        }
    }
', ['input' => ['title' => 'New Product']]);
```

### `mutate()` Method

```php
public function mutate(...): array
```

This is just an alias for `query()`. Both queries and mutations use the same GraphQL endpoint. It exists for code readability.

### Error Handling

The method throws `ShopifyApiException` in these cases:
- GraphQL-level errors (non-throttle) in the response body
- HTTP errors (non-429)
- Network errors
- Max retries exceeded

```php
try {
    $result = $graphql->query($shop, $token, $query);
} catch (ShopifyApiException $e) {
    $message = $e->getMessage();
    $errors = $e->getErrors(); // Array of Shopify error objects
    $code = $e->getCode();     // HTTP status code
}
```

---

## 2. `ShopifyApiClient` — Shopify Admin REST API

**File:** `src/Services/ShopifyApiClient.php`

For REST API endpoints. While GraphQL is preferred, some operations may still require REST.

### Registration

```php
$this->app->singleton(ShopifyApiClient::class, function ($app) {
    return new ShopifyApiClient(
        config('shopify-app.api_version'),
        config('shopify-app.rate_limit.rest'),  // ['bucket_size' => 40, 'leak_rate' => 2]
    );
});
```

### Core Method: `request()`

```php
public function request(
    string $method,
    string $shopDomain,
    string $accessToken,
    string $endpoint,
    array $data = [],
    array $query = []
): array
```

**What it does:**

1. **Rate limit check** — `$this->rateLimiter->throttle($shopDomain)` (cost = 1 for REST)
2. **Send request** to `https://{shop}/admin/api/{version}/{endpoint}` with `X-Shopify-Access-Token` header
3. **Update rate limiter** from `X-Shopify-Shop-Api-Call-Limit` header (e.g., `32/40`)
4. **Handle 429** — Wait and retry (up to `max_retries`)
5. **Return** decoded JSON response

### Convenience Methods

```php
$api = app(ShopifyApiClient::class);

// GET
$products = $api->get($shopDomain, $accessToken, 'products.json', ['limit' => 10]);

// POST
$product = $api->post($shopDomain, $accessToken, 'products.json', [
    'product' => ['title' => 'New Product']
]);

// PUT
$api->put($shopDomain, $accessToken, 'products/123.json', [
    'product' => ['title' => 'Updated Title']
]);

// DELETE
$api->delete($shopDomain, $accessToken, 'products/123.json');
```

### REST vs GraphQL Rate Limiting

| Aspect | REST | GraphQL |
|---|---|---|
| Bucket | 40 requests | 1000 cost points |
| Leak/Restore | 2 requests/sec | 50 points/sec |
| Header | `X-Shopify-Shop-Api-Call-Limit: 32/40` | `extensions.cost.throttleStatus` |
| Per-request cost | Always 1 | Variable (depends on query complexity) |

---

## 3. `RateLimiter` — Leaky Bucket Algorithm

**File:** `src/Services/RateLimiter.php`

Implements the **Leaky Bucket** rate limiting algorithm that Shopify uses. Both API clients use this internally.

### How Leaky Bucket Works

Imagine a bucket with a hole in the bottom:
- Each API request **adds** tokens to the bucket
- Tokens **leak** out at a constant rate
- If the bucket is full, you must **wait** for tokens to leak before making another request

### Per-Shop Tracking

Rate limits are **per-shop** in Shopify. The `RateLimiter` tracks a separate bucket for each shop domain using a static array:

```php
protected static array $buckets = [];
// Key: shop domain → Value: { tokens: float, last_request: float }
```

### Key Methods

**`throttle(string $shopDomain, int $cost = 1): void`**

Called before every API request. Logic:
1. Calculate how many tokens leaked since the last request
2. Subtract leaked tokens from the bucket
3. If adding `$cost` would exceed bucket size → `usleep()` until enough tokens leak
4. Add `$cost` to the bucket

**`handleRetryAfter(string $shopDomain, float $retryAfterSeconds): void`**

Called when a 429 is received. Sleeps for the specified duration and resets the bucket.

**`updateFromResponse(string $shopDomain, int $currentlyAvailable): void`**

Called after a successful response. Updates the bucket based on Shopify's reported available capacity (more accurate than local tracking).

**`reset(string $shopDomain): void`** — Clears a specific shop's bucket

**`resetAll(): void`** — Clears all buckets (useful in tests)

### Why Client-Side Rate Limiting?

Without this, your app would fire requests as fast as possible, get 429 errors, and waste time retrying. The leaky bucket algorithm **proactively slows down** requests to avoid 429s in the first place.

---

## Using Services in Your App

### Via Dependency Injection

```php
class ProductController extends Controller
{
    public function __construct(
        private GraphQLClient $graphql,
        private ShopifyApiClient $rest,
    ) {}

    public function index(Request $request)
    {
        $shop = $request->attributes->get('shopify_shop_domain');
        $token = $request->attributes->get('shopify_access_token');

        $products = $this->graphql->query($shop, $token, '{ products(first: 10) { edges { node { id title } } } }');

        return response()->json($products);
    }
}
```

### Via the `app()` Helper

```php
$graphql = app(GraphQLClient::class);
$rest = app(ShopifyApiClient::class);
```

### Via the Facade

```php
use LaravelShopify\Facades\ShopifyApp;

$result = ShopifyApp::query($shop, $token, $query);
$result = ShopifyApp::mutate($shop, $token, $mutation, $variables);
```

The facade wraps `GraphQLClient` only (not REST).
