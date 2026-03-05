<?php

use Illuminate\Support\Facades\Route;
use LaravelShopify\Http\Controllers\BillingController;
use LaravelShopify\Http\Controllers\TokenExchangeController;
use LaravelShopify\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Shopify App Routes
|--------------------------------------------------------------------------
|
| These routes are registered by the ShopifyAppServiceProvider.
| They handle token exchange, webhooks, and billing callbacks.
|
*/

Route::prefix('shopify')->group(function () {
    // Token Exchange endpoint — called by the frontend to exchange session tokens
    Route::post('/auth/token', [TokenExchangeController::class, 'exchange'])
        ->name('shopify.auth.token');

    // Webhook handler — receives all Shopify webhook POST requests
    Route::post('/webhooks', [WebhookController::class, 'handle'])
        ->name('shopify.webhooks');

    // Billing callback — Shopify redirects here after merchant approves/declines a charge
    Route::get('/billing/callback', [BillingController::class, 'callback'])
        ->name('shopify.billing.callback');
});
