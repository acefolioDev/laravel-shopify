# Laravel Shopify App

A comprehensive Laravel package that replicates the core functionality of the Shopify CLI (Node/Remix version). Handles the entire Shopify app lifecycle within the Laravel framework, adhering to 2026 Shopify standards — **App Bridge 4** and **Token Exchange**.

## Requirements

- PHP 8.2+
- Laravel 10 or 11
- Shopify Partner Account

## Installation

```bash
composer require acefolio/laravel-shopify
```

### Publish Assets

```bash
# Publish everything
php artisan vendor:publish --provider="LaravelShopify\ShopifyAppServiceProvider"

# Or publish individually
php artisan vendor:publish --tag=shopify-config
php artisan vendor:publish --tag=shopify-migrations
php artisan vendor:publish --tag=shopify-views
php artisan vendor:publish --tag=shopify-stubs
php artisan vendor:publish --tag=shopify-vite-plugin
```

### Run Migrations

```bash
php artisan migrate
```

## Configuration

Add these to your `.env` file:

```env
SHOPIFY_API_KEY=your-api-key
SHOPIFY_API_SECRET=your-api-secret
SHOPIFY_SCOPES=read_products,write_products,read_orders
SHOPIFY_APP_URL=https://your-app-url.com
SHOPIFY_API_VERSION=2025-01

# Tunnel (for development)
SHOPIFY_TUNNEL_DRIVER=ngrok
NGROK_AUTH_TOKEN=your-ngrok-token

# Billing (optional)
SHOPIFY_BILLING_ENABLED=true
SHOPIFY_BILLING_REQUIRED=true
SHOPIFY_BILLING_TEST=true

# Partners Dashboard Auto-Update (optional)
SHOPIFY_PARTNERS_AUTO_UPDATE=false
SHOPIFY_CLI_TOKEN=your-cli-token
SHOPIFY_APP_ID=your-app-id
```

See `config/shopify-app.php` for all available options.

---

## Authentication & Session Management

### Token Exchange (No OAuth Redirects)

This package uses Shopify's **Token Exchange** flow — the 2026 standard that replaces legacy OAuth redirect loops. The frontend obtains a session token from App Bridge, and the backend exchanges it for an access token.

```
[App Bridge 4] → Session Token (JWT) → [Laravel Backend] → Token Exchange → [Shopify] → Access Token
```

No redirect pages, no flashing, no auth callback routes needed for the primary flow.

### Middleware

#### `verify.shopify`

Validates the session token from the `Authorization: Bearer` header, performs token exchange or refresh as needed, and binds the shop context to the request.

```php
Route::middleware('verify.shopify')->group(function () {
    Route::get('/api/products', [ProductController::class, 'index']);
});
```

Access the shop context in your controllers:

```php
use LaravelShopify\Traits\ShopifyRequestContext;

class ProductController extends Controller
{
    use ShopifyRequestContext;

    public function index(Request $request)
    {
        $shopDomain = $this->getShopDomain($request);
        $accessToken = $this->getAccessToken($request);
        $session = $this->getShopifySession($request);
        $shop = $this->getShop($request);

        // ... your logic
    }
}
```

#### `verify.billing`

Checks for an active billing plan. If none exists, returns a 402 with the App Bridge redirect header pointing to Shopify's checkout page.

```php
Route::middleware(['verify.shopify', 'verify.billing'])->group(function () {
    Route::get('/api/dashboard', [DashboardController::class, 'index']);
});

// Or require a specific plan
Route::middleware(['verify.shopify', 'verify.billing:pro'])->group(function () {
    Route::get('/api/advanced', [AdvancedController::class, 'index']);
});
```

#### `verify.webhook.hmac`

Validates the HMAC signature on incoming Shopify webhooks.

#### `verify.app.proxy`

Validates the signature on Shopify App Proxy requests.

### Expiring Offline Access Tokens

The package fully supports Shopify's expiring offline tokens with automatic refresh token rotation. When a token is about to expire (within the configurable buffer window), the middleware automatically refreshes it.

```php
// config/shopify-app.php
'offline_tokens' => [
    'expiring' => true,
    'refresh_buffer_seconds' => 300, // Refresh 5 min before expiry
],
```

---

## Artisan Commands

### `shopify:app:dev`

Start a full development environment — tunnel, app URL update, Laravel server, and Vite dev server.

```bash
php artisan shopify:app:dev

# Options
php artisan shopify:app:dev --tunnel=cloudflare
php artisan shopify:app:dev --port=8000 --vite-port=5173
php artisan shopify:app:dev --no-tunnel
php artisan shopify:app:dev --no-update
```

### `shopify:app:deploy`

Bundle assets, optimize Laravel, and prepare for production.

```bash
php artisan shopify:app:deploy

# Options
php artisan shopify:app:deploy --skip-build
php artisan shopify:app:deploy --skip-optimize
```

### `shopify:generate:webhook`

Scaffold a webhook Job class and register it in the config.

```bash
php artisan shopify:generate:webhook PRODUCTS_UPDATE
php artisan shopify:generate:webhook APP_UNINSTALLED --force
```

### `shopify:generate:extension`

Scaffold Theme App Extensions or UI Extensions.

```bash
# Theme App Extension
php artisan shopify:generate:extension my-theme-block --type=theme

# UI Extension
php artisan shopify:generate:extension my-admin-block --type=ui
```

---

## API Client

### GraphQL Client (with Leaky Bucket Rate Limiting)

```php
use LaravelShopify\Services\GraphQLClient;

$graphql = app(GraphQLClient::class);

$result = $graphql->query($shopDomain, $accessToken, '
    {
        products(first: 10) {
            edges {
                node {
                    id
                    title
                }
            }
        }
    }
');

// Mutations
$result = $graphql->mutate($shopDomain, $accessToken, '
    mutation productCreate($input: ProductInput!) {
        productCreate(input: $input) {
            product { id title }
            userErrors { field message }
        }
    }
', ['input' => ['title' => 'New Product']]);
```

### REST Client

```php
use LaravelShopify\Services\ShopifyApiClient;

$api = app(ShopifyApiClient::class);

$products = $api->get($shopDomain, $accessToken, 'products.json', ['limit' => 10]);
$product = $api->post($shopDomain, $accessToken, 'products.json', ['product' => [...]]);
$api->put($shopDomain, $accessToken, 'products/123.json', ['product' => [...]]);
$api->delete($shopDomain, $accessToken, 'products/123.json');
```

### Facade

```php
use LaravelShopify\Facades\ShopifyApp;

$result = ShopifyApp::query($shopDomain, $accessToken, $query);
```

Both clients implement the **Leaky Bucket algorithm** for automatic rate-limit handling with configurable retry logic.

---

## Billing

### Configuration

```php
// config/shopify-app.php
'billing' => [
    'enabled' => true,
    'required' => true,

    'plans' => [
        'basic' => [
            'name' => 'Basic Plan',
            'type' => 'recurring',
            'price' => 9.99,
            'currency' => 'USD',
            'interval' => 'EVERY_30_DAYS',
            'trial_days' => 7,
            'test' => true,
        ],
        'pro' => [
            'name' => 'Pro Plan',
            'type' => 'recurring',
            'price' => 29.99,
            'currency' => 'USD',
            'interval' => 'EVERY_30_DAYS',
            'trial_days' => 14,
            'test' => true,
            'capped_amount' => 100.00,
            'terms' => 'Usage charges for API calls',
        ],
        'lifetime' => [
            'name' => 'Lifetime Access',
            'type' => 'one_time',
            'price' => 199.99,
            'currency' => 'USD',
            'test' => true,
        ],
    ],
],
```

### Programmatic Usage

```php
use LaravelShopify\Services\BillingService;

$billing = app(BillingService::class);

// Create a charge and get the confirmation URL
$confirmationUrl = $billing->createCharge($shopDomain, $accessToken, 'pro');

// Check active subscription
$subscription = $billing->checkActiveSubscription($shopDomain, $accessToken);

// Confirm after merchant approves
$plan = $billing->confirmCharge($shopDomain, 'pro', $chargeId);
```

### App Bridge Redirect Pattern

All billing redirects use the `Link` header pattern for App Bridge iframe breakout:

```
Link: <https://checkout-url>; rel="app-bridge-redirect-endpoint"
```

---

## Webhooks

### Declarative Registration

```php
// config/shopify-app.php
'webhooks' => [
    'APP_UNINSTALLED' => \App\Jobs\Shopify\AppUninstalledJob::class,
    'PRODUCTS_UPDATE' => \App\Jobs\Shopify\ProductsUpdateJob::class,
],
```

Webhooks are automatically registered with Shopify when a shop installs your app.

### Webhook Jobs

```bash
php artisan shopify:generate:webhook PRODUCTS_UPDATE
```

Generated job:

```php
class ProductsUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $shopDomain;
    public array $data;
    public string $topic;
    public string $apiVersion;

    public function handle(): void
    {
        // Your webhook handling logic
    }
}
```

### CSRF Exemption

Add the webhook path to your `VerifyCsrfToken` middleware exceptions:

```php
protected $except = [
    'shopify/webhooks',
];
```

---

## Frontend Integration

### Blade Layout (App Bridge 4)

```blade
@extends('shopify-app::layouts.shopify-app')

@section('content')
    <h1>My Shopify App</h1>

    <script>
        // Use the global authenticatedFetch helper
        authenticatedFetch('/api/products')
            .then(res => res.json())
            .then(data => console.log(data));
    </script>
@endsection
```

### React/Inertia Layout

Use the React-optimized layout:

```blade
@extends('shopify-app::layouts.shopify-react')
```

Add the Inertia data-sharing middleware to your stack:

```php
// app/Http/Kernel.php
'web' => [
    // ...
    \LaravelShopify\Http\Middleware\ShareShopifyInertiaData::class,
],
```

### Vite Plugin

Publish and use the included Vite plugin:

```bash
php artisan vendor:publish --tag=shopify-vite-plugin
```

```js
// vite.config.js
import shopifyAppBridge from './vite-plugin-shopify/index.js';

export default defineConfig({
    plugins: [
        shopifyAppBridge({
            apiKey: process.env.SHOPIFY_API_KEY,
        }),
        laravel({
            input: ['resources/js/app.jsx'],
            refresh: true,
        }),
    ],
});
```

The plugin automatically:
- Injects the App Bridge 4 CDN script
- Configures HMR for embedded iframe context
- Provides `getSessionToken()` and `authenticatedFetch()` helpers

### Navigation Bridge

Sync Laravel routes with the Shopify Admin address bar:

```php
use LaravelShopify\Navigation\NavigationBridge;

// Generate nav items
$menu = NavigationBridge::buildMenu([
    ['label' => 'Dashboard', 'route' => 'dashboard'],
    ['label' => 'Products', 'route' => 'products.index'],
    ['label' => 'Settings', 'route' => 'settings'],
], Route::currentRouteName());

// For Inertia apps
$sharedData = NavigationBridge::inertiaSharedData($menuItems, $currentRoute);
```

In Blade views, include the sync script:

```blade
{!! \LaravelShopify\Navigation\NavigationBridge::syncScript() !!}
```

---

## Events

The package dispatches lifecycle events you can listen for:

| Event | When |
|---|---|
| `ShopInstalled` | First-time token exchange for a shop |
| `ShopUninstalled` | (Dispatch in your APP_UNINSTALLED webhook job) |
| `ShopTokenRefreshed` | Token refreshed for an existing shop |

```php
// EventServiceProvider
protected $listen = [
    \LaravelShopify\Events\ShopInstalled::class => [
        \App\Listeners\SetupNewShop::class,
    ],
];
```

---

## Helpers

### Shop Domain Utilities

```php
use LaravelShopify\Support\ShopifyHelper;

ShopifyHelper::sanitizeShopDomain('my-store');
// → "my-store.myshopify.com"

ShopifyHelper::adminUrl('my-store.myshopify.com', 'products');
// → "https://my-store.myshopify.com/admin/products"

ShopifyHelper::embeddedAppUrl('my-store.myshopify.com');
// → "https://admin.shopify.com/store/my-store/apps/{api_key}"

ShopifyHelper::shopFromHost($encodedHost);
// → "my-store.myshopify.com"
```

### HMAC Verification

```php
use LaravelShopify\Support\HmacVerifier;

HmacVerifier::verifyWebhook($body, $hmacHeader);
HmacVerifier::verifyProxy($request);
HmacVerifier::verifyOAuth($queryParams);
HmacVerifier::isValidShopDomain('my-store.myshopify.com'); // true
```

---

## Package Structure

```
├── config/
│   └── shopify-app.php              # Full configuration
├── database/migrations/
│   ├── create_shopify_shops_table
│   ├── create_shopify_sessions_table
│   └── create_shopify_plans_table
├── resources/views/layouts/
│   ├── shopify-app.blade.php        # Blade + App Bridge 4
│   └── shopify-react.blade.php      # React/Inertia + App Bridge 4
├── routes/
│   └── shopify.php                  # Token exchange, webhooks, billing
├── src/
│   ├── Auth/
│   │   ├── SessionToken.php         # JWT validation
│   │   └── TokenExchange.php        # Token exchange + refresh
│   ├── Console/Commands/
│   │   ├── AppDevCommand.php        # shopify:app:dev
│   │   ├── AppDeployCommand.php     # shopify:app:deploy
│   │   ├── GenerateWebhookCommand.php
│   │   └── GenerateExtensionCommand.php
│   ├── Events/
│   │   ├── ShopInstalled.php
│   │   ├── ShopUninstalled.php
│   │   └── ShopTokenRefreshed.php
│   ├── Exceptions/
│   │   ├── ShopifyApiException.php
│   │   └── TokenExchangeException.php
│   ├── Facades/
│   │   └── ShopifyApp.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── BillingController.php
│   │   │   ├── TokenExchangeController.php
│   │   │   └── WebhookController.php
│   │   └── Middleware/
│   │       ├── ShareShopifyInertiaData.php
│   │       ├── VerifyAppProxy.php
│   │       ├── VerifyBilling.php
│   │       ├── VerifyShopify.php
│   │       └── VerifyWebhookHmac.php
│   ├── Models/
│   │   ├── Plan.php
│   │   ├── Session.php
│   │   └── Shop.php
│   ├── Navigation/
│   │   └── NavigationBridge.php
│   ├── Services/
│   │   ├── BillingService.php
│   │   ├── GraphQLClient.php
│   │   ├── RateLimiter.php
│   │   ├── ShopifyApiClient.php
│   │   └── WebhookRegistrar.php
│   ├── Support/
│   │   ├── HmacVerifier.php
│   │   └── ShopifyHelper.php
│   ├── Traits/
│   │   └── ShopifyRequestContext.php
│   └── ShopifyAppServiceProvider.php
├── stubs/
│   └── webhook-job.stub
├── vite-plugin/
│   ├── index.js
│   └── package.json
├── composer.json
└── README.md
```

## License

MIT
