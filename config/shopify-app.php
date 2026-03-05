<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shopify App Credentials
    |--------------------------------------------------------------------------
    |
    | Your Shopify App's API key and secret, found in the Partners Dashboard.
    |
    */

    'api_key' => env('SHOPIFY_API_KEY', ''),
    'api_secret' => env('SHOPIFY_API_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    |
    | The API scopes your app requires. Comma-separated string.
    | Example: 'read_products,write_products,read_orders'
    |
    */

    'scopes' => env('SHOPIFY_SCOPES', 'read_products'),

    /*
    |--------------------------------------------------------------------------
    | App URL & Redirect URI
    |--------------------------------------------------------------------------
    */

    'app_url' => env('SHOPIFY_APP_URL', env('APP_URL', 'https://localhost')),
    'redirect_uri' => env('SHOPIFY_REDIRECT_URI', '/shopify/auth/callback'),

    /*
    |--------------------------------------------------------------------------
    | API Version
    |--------------------------------------------------------------------------
    |
    | The Shopify Admin API version to use.
    |
    */

    'api_version' => env('SHOPIFY_API_VERSION', '2025-01'),

    /*
    |--------------------------------------------------------------------------
    | App Bridge
    |--------------------------------------------------------------------------
    |
    | App Bridge 4 configuration.
    |
    */

    'app_bridge' => [
        'enabled' => true,
        'cdn_url' => 'https://cdn.shopify.com/shopifycloud/app-bridge.js',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Mode
    |--------------------------------------------------------------------------
    |
    | 'token_exchange' (recommended, 2026 standard) or 'authorization_code'.
    |
    */

    'auth_mode' => env('SHOPIFY_AUTH_MODE', 'token_exchange'),

    /*
    |--------------------------------------------------------------------------
    | Session Storage
    |--------------------------------------------------------------------------
    |
    | Driver for session storage. 'eloquent' uses the built-in models.
    |
    */

    'session' => [
        'driver' => 'eloquent',
        'table' => 'shopify_sessions',
        'expire_after' => 86400, // seconds — 24 hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline Access Token Expiry & Refresh
    |--------------------------------------------------------------------------
    |
    | Shopify's expiring offline tokens rotate via refresh tokens.
    | refresh_buffer_seconds: how early (in seconds) to proactively refresh.
    |
    */

    'offline_tokens' => [
        'expiring' => true,
        'refresh_buffer_seconds' => 300, // 5 minutes before expiry
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Register webhooks declaratively. Each entry maps a topic to a Job class.
    | The package will register them with Shopify when the app is installed.
    |
    */

    'webhooks' => [
        // 'APP_UNINSTALLED' => \App\Jobs\Shopify\AppUninstalledJob::class,
        // 'PRODUCTS_UPDATE' => \App\Jobs\Shopify\ProductsUpdateJob::class,
    ],

    'webhook_path' => '/shopify/webhooks',

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | Define your app's billing plans here.
    |
    */

    'billing' => [
        'enabled' => env('SHOPIFY_BILLING_ENABLED', false),
        'required' => env('SHOPIFY_BILLING_REQUIRED', false),

        'plans' => [
            // 'basic' => [
            //     'name' => 'Basic Plan',
            //     'type' => 'recurring', // 'recurring' or 'one_time'
            //     'price' => 9.99,
            //     'currency' => 'USD',
            //     'interval' => 'EVERY_30_DAYS', // EVERY_30_DAYS or ANNUAL
            //     'trial_days' => 7,
            //     'test' => env('SHOPIFY_BILLING_TEST', true),
            //     'capped_amount' => null, // for usage billing
            //     'terms' => null,
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tunnel
    |--------------------------------------------------------------------------
    |
    | Configuration for the dev tunnel used by shopify:app:dev.
    |
    */

    'tunnel' => [
        'driver' => env('SHOPIFY_TUNNEL_DRIVER', 'ngrok'), // 'ngrok' or 'cloudflare'
        'ngrok_auth_token' => env('NGROK_AUTH_TOKEN', ''),
        'cloudflare_bin' => env('CLOUDFLARE_BIN', 'cloudflared'),
        'port' => env('SHOPIFY_DEV_PORT', 8000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Partners Dashboard Auto-Update
    |--------------------------------------------------------------------------
    |
    | If true, the shopify:app:dev command will attempt to update
    | the app URLs in the Partners Dashboard via the CLI token.
    |
    */

    'partners' => [
        'auto_update' => env('SHOPIFY_PARTNERS_AUTO_UPDATE', false),
        'cli_token' => env('SHOPIFY_CLI_TOKEN', ''),
        'app_id' => env('SHOPIFY_APP_ID', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting (Leaky Bucket)
    |--------------------------------------------------------------------------
    |
    | Controls how the GraphQL/REST client handles Shopify rate limits.
    |
    */

    'rate_limit' => [
        'rest' => [
            'bucket_size' => 40,
            'leak_rate' => 2, // requests per second
        ],
        'graphql' => [
            'max_cost' => 1000,
            'restore_rate' => 50, // points per second
        ],
        'retry_after_seconds' => 1,
        'max_retries' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | Customize the database table names used by the package.
    |
    */

    'tables' => [
        'shops' => 'shopify_shops',
        'sessions' => 'shopify_sessions',
        'plans' => 'shopify_plans',
    ],

];
