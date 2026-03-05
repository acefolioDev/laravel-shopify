<?php

namespace LaravelShopify;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LaravelShopify\Auth\TokenExchange;
use LaravelShopify\Console\Commands\AppDevCommand;
use LaravelShopify\Console\Commands\AppDeployCommand;
use LaravelShopify\Console\Commands\GenerateExtensionCommand;
use LaravelShopify\Console\Commands\GenerateWebhookCommand;
use LaravelShopify\Http\Middleware\VerifyAppProxy;
use LaravelShopify\Http\Middleware\VerifyBilling;
use LaravelShopify\Http\Middleware\VerifyShopify;
use LaravelShopify\Http\Middleware\VerifyWebhookHmac;
use LaravelShopify\Services\BillingService;
use LaravelShopify\Services\GraphQLClient;
use LaravelShopify\Services\ShopifyApiClient;
use LaravelShopify\Services\WebhookRegistrar;

class ShopifyAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/shopify-app.php', 'shopify-app');

        $this->app->singleton(TokenExchange::class, function ($app) {
            return new TokenExchange(
                config('shopify-app.api_key'),
                config('shopify-app.api_secret'),
                config('shopify-app.scopes'),
            );
        });

        $this->app->singleton(GraphQLClient::class, function ($app) {
            return new GraphQLClient(
                config('shopify-app.api_version'),
                config('shopify-app.rate_limit.graphql'),
            );
        });

        $this->app->singleton(ShopifyApiClient::class, function ($app) {
            return new ShopifyApiClient(
                config('shopify-app.api_version'),
                config('shopify-app.rate_limit.rest'),
            );
        });

        $this->app->singleton(BillingService::class, function ($app) {
            return new BillingService(
                $app->make(GraphQLClient::class),
                config('shopify-app.billing'),
            );
        });

        $this->app->singleton(WebhookRegistrar::class, function ($app) {
            return new WebhookRegistrar(
                $app->make(GraphQLClient::class),
            );
        });
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerMigrations();
        $this->registerRoutes();
        $this->registerMiddleware();
        $this->registerCommands();
        $this->registerViews();
    }

    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/shopify-app.php' => config_path('shopify-app.php'),
            ], 'shopify-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'shopify-migrations');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/shopify-app'),
            ], 'shopify-views');

            $this->publishes([
                __DIR__ . '/../stubs' => base_path('stubs/shopify'),
            ], 'shopify-stubs');

            $this->publishes([
                __DIR__ . '/../vite-plugin' => base_path('vite-plugin-shopify'),
            ], 'shopify-vite-plugin');
        }
    }

    protected function registerMigrations(): void
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }
    }

    protected function registerRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/shopify.php');
    }

    protected function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('verify.shopify', VerifyShopify::class);
        $router->aliasMiddleware('verify.billing', VerifyBilling::class);
        $router->aliasMiddleware('verify.webhook.hmac', VerifyWebhookHmac::class);
        $router->aliasMiddleware('verify.app.proxy', VerifyAppProxy::class);
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                AppDevCommand::class,
                AppDeployCommand::class,
                GenerateWebhookCommand::class,
                GenerateExtensionCommand::class,
            ]);
        }
    }

    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'shopify-app');
    }
}
