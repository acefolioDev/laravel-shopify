# 18 - ShopifyApp Facade

The `ShopifyApp` facade provides a convenient static interface to the `GraphQLClient` service.

**Source file:** `src/Facades/ShopifyApp.php`

---

## What Is a Facade?

In Laravel, a facade is a static-looking interface to a class that's registered in the service container. When you call `ShopifyApp::query(...)`, Laravel resolves the `GraphQLClient` singleton from the container and calls `query()` on it.

---

## The Facade Class

```php
namespace LaravelShopify\Facades;

use Illuminate\Support\Facades\Facade;
use LaravelShopify\Services\GraphQLClient;

/**
 * @method static array query(string $shop, string $accessToken, string $query, array $variables = [])
 * @method static array mutate(string $shop, string $accessToken, string $query, array $variables = [])
 */
class ShopifyApp extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GraphQLClient::class;
    }
}
```

### `getFacadeAccessor()`

Returns `GraphQLClient::class` — this tells Laravel to resolve the `GraphQLClient` singleton from the container whenever a method is called on the facade.

---

## Available Methods

The facade proxies all methods of `GraphQLClient`:

### `ShopifyApp::query()`

```php
use LaravelShopify\Facades\ShopifyApp;

$result = ShopifyApp::query(
    'my-store.myshopify.com',
    'shpat_...',
    '{
        products(first: 10) {
            edges {
                node { id title }
            }
        }
    }'
);
```

### `ShopifyApp::mutate()`

```php
$result = ShopifyApp::mutate(
    'my-store.myshopify.com',
    'shpat_...',
    'mutation productCreate($input: ProductInput!) {
        productCreate(input: $input) {
            product { id title }
            userErrors { field message }
        }
    }',
    ['input' => ['title' => 'New Product']]
);
```

> **Note:** `mutate()` is just an alias for `query()` — both use the same GraphQL endpoint.

---

## Registration

The facade alias is registered via `composer.json` auto-discovery:

```json
{
    "extra": {
        "laravel": {
            "aliases": {
                "ShopifyApp": "LaravelShopify\\Facades\\ShopifyApp"
            }
        }
    }
}
```

This means you can use `ShopifyApp::` anywhere without importing — but it's better practice to import it explicitly:

```php
use LaravelShopify\Facades\ShopifyApp;
```

---

## Facade vs Direct Resolution

These three approaches are equivalent:

```php
// 1. Facade (static syntax)
use LaravelShopify\Facades\ShopifyApp;
$result = ShopifyApp::query($shop, $token, $query);

// 2. Dependency injection (recommended for testability)
public function __construct(private GraphQLClient $graphql) {}
$result = $this->graphql->query($shop, $token, $query);

// 3. App helper
$graphql = app(GraphQLClient::class);
$result = $graphql->query($shop, $token, $query);
```

### When to Use Each

| Approach | Best For |
|---|---|
| **Facade** | Quick scripts, Artisan commands, one-off queries |
| **Dependency injection** | Controllers, services (best for testing) |
| **App helper** | When DI isn't available (closures, middleware) |

---

## What the Facade Does NOT Cover

The facade only wraps `GraphQLClient`. For other services, use dependency injection or `app()`:

```php
// REST API — no facade
$rest = app(ShopifyApiClient::class);
$rest->get($shop, $token, 'products.json');

// Billing — no facade
$billing = app(BillingService::class);
$billing->createCharge($shop, $token, 'basic');

// Webhook registration — no facade
$registrar = app(WebhookRegistrar::class);
$registrar->registerAll($shop, $token);

// Token exchange — no facade
$exchange = app(TokenExchange::class);
$session = $exchange->ensureSession($shop, $jwt);
```

---

## Typical Usage in a Controller

```php
use LaravelShopify\Facades\ShopifyApp;
use LaravelShopify\Traits\ShopifyRequestContext;

class ProductController extends Controller
{
    use ShopifyRequestContext;

    public function index(Request $request)
    {
        $shop = $this->getShopDomain($request);
        $token = $this->getAccessToken($request);

        $result = ShopifyApp::query($shop, $token, '{
            products(first: 10) {
                edges { node { id title } }
            }
        }');

        return response()->json($result);
    }

    public function store(Request $request)
    {
        $shop = $this->getShopDomain($request);
        $token = $this->getAccessToken($request);

        $result = ShopifyApp::mutate($shop, $token, '
            mutation productCreate($input: ProductInput!) {
                productCreate(input: $input) {
                    product { id title }
                    userErrors { field message }
                }
            }
        ', ['input' => $request->validated()]);

        return response()->json($result);
    }
}
```
