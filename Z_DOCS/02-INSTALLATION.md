# 02 - Installation & Setup

## Prerequisites

- **PHP 8.2+**
- **Laravel 10, 11, or 12**
- **Shopify Partner Account** — You need an app created in the [Shopify Partners Dashboard](https://partners.shopify.com)
- **Guzzle 7.5+** (comes with Laravel by default)

## Step 1: Install via Composer

```bash
composer require acefolio/laravel-shopify
```

The package uses Laravel's auto-discovery, so the `ShopifyAppServiceProvider` and `ShopifyApp` facade are registered automatically. You do NOT need to manually add them to `config/app.php`.

### What Auto-Discovery Registers

This is defined in `composer.json` under `extra.laravel`:

```json
{
    "providers": ["LaravelShopify\\ShopifyAppServiceProvider"],
    "aliases": {
        "ShopifyApp": "LaravelShopify\\Facades\\ShopifyApp"
    }
}
```

## Step 2: Publish Assets

You can publish everything at once or individually:

### Publish Everything

```bash
php artisan vendor:publish --provider="LaravelShopify\ShopifyAppServiceProvider"
```

### Publish Individually

```bash
# Config file → config/shopify-app.php
php artisan vendor:publish --tag=shopify-config

# Migrations → database/migrations/
php artisan vendor:publish --tag=shopify-migrations

# Blade views → resources/views/vendor/shopify-app/
php artisan vendor:publish --tag=shopify-views

# Webhook job stub → stubs/shopify/
php artisan vendor:publish --tag=shopify-stubs

# Vite plugin → vite-plugin-shopify/
php artisan vendor:publish --tag=shopify-vite-plugin
```

### What Each Publish Tag Gives You

| Tag | Destination | Purpose |
|---|---|---|
| `shopify-config` | `config/shopify-app.php` | All configuration options |
| `shopify-migrations` | `database/migrations/` | 3 migration files (shops, sessions, plans) |
| `shopify-views` | `resources/views/vendor/shopify-app/` | Blade layouts you can customize |
| `shopify-stubs` | `stubs/shopify/` | Webhook job template for code generation |
| `shopify-vite-plugin` | `vite-plugin-shopify/` | Vite plugin for App Bridge 4 + HMR |

## Step 3: Run Migrations

```bash
php artisan migrate
```

This creates 3 tables:

- **`shopify_shops`** — Stores shop info, access tokens, installation status
- **`shopify_sessions`** — Stores session data (offline and online tokens)
- **`shopify_plans`** — Stores billing plan subscriptions

> **Note:** The package loads migrations automatically even without publishing. Publishing is only needed if you want to customize the migration files.

## Step 4: Configure Environment Variables

Add these to your `.env` file:

```env
# Required — from your Shopify Partners Dashboard
SHOPIFY_API_KEY=your-api-key
SHOPIFY_API_SECRET=your-api-secret
SHOPIFY_SCOPES=read_products,write_products,read_orders
SHOPIFY_APP_URL=https://your-app-url.com
SHOPIFY_API_VERSION=2025-01
```

### Where to Find These Values

1. Go to [partners.shopify.com](https://partners.shopify.com)
2. Click on your app
3. Go to **App setup** → **Client credentials**
4. Copy the **Client ID** (this is `SHOPIFY_API_KEY`) and **Client Secret** (this is `SHOPIFY_API_SECRET`)

## Step 5: CSRF Exemption for Webhooks

Shopify sends webhook POST requests to your app. These don't include a CSRF token, so you need to exempt the webhook path.

### Laravel 10 (Kernel-based)

```php
// app/Http/Kernel.php (or app/Http/Middleware/VerifyCsrfToken.php)
protected $except = [
    'shopify/webhooks',
];
```

### Laravel 11+ (Bootstrap-based)

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: [
        'shopify/webhooks',
    ]);
})
```

## Step 6: Vite Plugin Setup (If Using Vite)

If you published the Vite plugin:

```bash
php artisan vendor:publish --tag=shopify-vite-plugin
```

Then add it to your `vite.config.js`:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
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

## Verification

After installation, verify everything is working:

```bash
# Check the config is loaded
php artisan config:show shopify-app

# Check migrations ran
php artisan migrate:status

# Check routes are registered
php artisan route:list --name=shopify

# Check middleware is registered
# You should see: verify.shopify, verify.billing, verify.webhook.hmac, verify.app.proxy
```

## Next Steps

- Read **03-CONFIGURATION.md** to understand all config options
- Read **05-AUTHENTICATION.md** to understand the token exchange flow
