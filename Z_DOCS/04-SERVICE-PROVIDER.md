# 04 - Service Provider (`ShopifyAppServiceProvider`)

The service provider is the **entry point** of the package. It's the first thing Laravel loads and it wires everything together.

**Source file:** `src/ShopifyAppServiceProvider.php`

---

## What Is a Service Provider?

In Laravel, a service provider is a class that tells the framework how to bind services into the container, register routes, publish assets, etc. This package's service provider does all of that.

It's auto-discovered by Laravel — you don't need to add it manually to `config/app.php`.

---

## The `register()` Method

The `register()` method runs **before** the app is fully booted. It's used to bind services into Laravel's service container as singletons.

### Config Merge

```php
$this->mergeConfigFrom(__DIR__ . '/../config/shopify-app.php', 'shopify-app');
```

This makes the package's default config available even if the user hasn't published it. If the user publishes and customizes `config/shopify-app.php`, their values override the defaults.

### Singleton Bindings

The following services are registered as **singletons** (one instance per request):

| Service | What It Does |
|---|---|
| `TokenExchange` | Exchanges App Bridge JWTs for access tokens. Receives `api_key`, `api_secret`, and `scopes` from config. |
| `GraphQLClient` | Makes GraphQL API calls with rate limiting. Receives `api_version` and `rate_limit.graphql` config. |
| `ShopifyApiClient` | Makes REST API calls with rate limiting. Receives `api_version` and `rate_limit.rest` config. |
| `BillingService` | Creates charges, checks subscriptions, confirms billing. Depends on `GraphQLClient` and `billing` config. |
| `WebhookRegistrar` | Registers webhooks with Shopify via GraphQL. Depends on `GraphQLClient`. |

### Dependency Chain

```
BillingService ──depends on──► GraphQLClient
WebhookRegistrar ──depends on──► GraphQLClient
TokenExchange ──standalone (uses Guzzle directly)──
ShopifyApiClient ──standalone (uses Guzzle directly)──
```

### How to Resolve These Services

In controllers or anywhere in Laravel:

```php
// Via dependency injection (preferred)
public function __construct(GraphQLClient $graphql) { ... }

// Via the app() helper
$graphql = app(GraphQLClient::class);

// Via the facade (GraphQLClient only)
ShopifyApp::query($shop, $token, $query);
```

---

## The `boot()` Method

The `boot()` method runs **after** all providers are registered. It sets up routes, middleware, views, commands, and publishable assets.

### `registerPublishing()`

Only runs in console context (`runningInConsole()`). Registers 5 publish groups:

| Tag | Source → Destination |
|---|---|
| `shopify-config` | `config/shopify-app.php` → `config_path('shopify-app.php')` |
| `shopify-migrations` | `database/migrations/` → `database_path('migrations')` |
| `shopify-views` | `resources/views/` → `resource_path('views/vendor/shopify-app')` |
| `shopify-stubs` | `stubs/` → `base_path('stubs/shopify')` |
| `shopify-vite-plugin` | `vite-plugin/` → `base_path('vite-plugin-shopify')` |

### `registerMigrations()`

```php
$this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
```

Loads migrations directly from the package. You **don't need to publish** migrations for them to work — they run automatically on `php artisan migrate`.

### `registerRoutes()`

```php
$this->loadRoutesFrom(__DIR__ . '/../routes/shopify.php');
```

Registers 3 routes under the `/shopify` prefix. See **08-ROUTES.md** for details.

### `registerMiddleware()`

Registers 4 middleware aliases with the Laravel router:

```php
$router->aliasMiddleware('verify.shopify', VerifyShopify::class);
$router->aliasMiddleware('verify.billing', VerifyBilling::class);
$router->aliasMiddleware('verify.webhook.hmac', VerifyWebhookHmac::class);
$router->aliasMiddleware('verify.app.proxy', VerifyAppProxy::class);
```

These are now available as middleware names in your `routes/api.php` or anywhere else.

### `registerCommands()`

Registers 4 Artisan commands (only in console):

```php
$this->commands([
    AppDevCommand::class,       // shopify:app:dev
    AppDeployCommand::class,    // shopify:app:deploy
    GenerateWebhookCommand::class,    // shopify:generate:webhook
    GenerateExtensionCommand::class,  // shopify:generate:extension
]);
```

### `registerViews()`

```php
$this->loadViewsFrom(__DIR__ . '/../resources/views', 'shopify-app');
```

Registers the Blade views under the `shopify-app` namespace. Use them as:

```blade
@extends('shopify-app::layouts.shopify-app')
@extends('shopify-app::layouts.shopify-react')
```

If the user publishes and edits the views in `resources/views/vendor/shopify-app/`, Laravel automatically uses the published version instead.

---

## Execution Order Summary

1. **`register()`** — Bind singletons into the container
2. **`boot()`** — Set up everything else:
   - Publishing rules
   - Migration loading
   - Route loading
   - Middleware registration
   - Command registration
   - View registration
