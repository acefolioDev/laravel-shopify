# 01 - Package Overview & Architecture

## What Is This Package?

`acefolio/laravel-shopify` is a Laravel package that replicates the core functionality of the official Shopify CLI (Node/Remix version) but for the Laravel ecosystem. It handles the **entire Shopify app lifecycle** — authentication, API calls, billing, webhooks, and development tooling — using the **2026 Shopify standards**: **App Bridge 4** and **Token Exchange**.

## Why Does This Package Exist?

Shopify's official tooling is built around Node.js/Remix. If you want to build a Shopify app with Laravel, you need to implement all of this yourself. This package bridges that gap by providing:

- **Token Exchange** (replaces the old OAuth redirect flow)
- **App Bridge 4** integration (no more App Bridge 2/3 migration pain)
- **Leaky Bucket rate limiting** for both REST and GraphQL APIs
- **Billing** (recurring subscriptions, one-time charges, usage-based pricing)
- **Webhook registration and handling** via Laravel Jobs
- **Artisan commands** that mirror `shopify app dev` and `shopify app deploy`
- **Vite plugin** for HMR inside Shopify's embedded iframe

## High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                     Shopify Admin (iframe)                       │
│                                                                 │
│   App Bridge 4 CDN ──► getSessionToken() ──► authenticatedFetch │
└────────────────────────────────┬────────────────────────────────┘
                                 │ Authorization: Bearer <JWT>
                                 ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Laravel Backend                            │
│                                                                 │
│  ┌──────────────────┐    ┌───────────────────┐                  │
│  │ VerifyShopify    │───►│ TokenExchange     │                  │
│  │ Middleware        │    │ Service           │                  │
│  └──────────────────┘    └───────┬───────────┘                  │
│           │                      │                              │
│           ▼                      ▼                              │
│  ┌──────────────────┐    ┌───────────────────┐                  │
│  │ Your Controllers │    │ Shopify Admin API │                  │
│  │ (with context)   │    │ (token exchange)  │                  │
│  └──────────────────┘    └───────────────────┘                  │
│           │                                                     │
│           ▼                                                     │
│  ┌──────────────────────────────────────────┐                   │
│  │ Services: GraphQL, REST, Billing,        │                   │
│  │ WebhookRegistrar, RateLimiter            │                   │
│  └──────────────────────────────────────────┘                   │
│           │                                                     │
│           ▼                                                     │
│  ┌──────────────────────────────────────────┐                   │
│  │ Models: Shop, Session, Plan              │                   │
│  │ (Eloquent, stored in MySQL/PostgreSQL)   │                   │
│  └──────────────────────────────────────────┘                   │
└─────────────────────────────────────────────────────────────────┘
```

## The Authentication Flow (Token Exchange)

The old way (OAuth redirect) looked like this:
```
User opens app → Redirect to Shopify → User approves → Redirect back → Exchange code for token
```

The new way (Token Exchange, this package) looks like this:
```
User opens app → App Bridge gives JWT → Backend exchanges JWT for access token → Done
```

No redirects. No flashing. No callback routes for auth. The user never leaves the Shopify Admin.

## Package Directory Structure

| Directory | Purpose |
|---|---|
| `config/` | The `shopify-app.php` configuration file |
| `database/migrations/` | 3 tables: `shopify_shops`, `shopify_sessions`, `shopify_plans` |
| `resources/views/` | Blade layouts for App Bridge 4 (Blade and React/Inertia) |
| `routes/` | Package routes: token exchange, webhooks, billing callback |
| `src/Auth/` | JWT validation (`SessionToken`) and Token Exchange (`TokenExchange`) |
| `src/Console/Commands/` | Artisan commands: `app:dev`, `app:deploy`, `generate:webhook`, `generate:extension` |
| `src/Events/` | Laravel events: `ShopInstalled`, `ShopUninstalled`, `ShopTokenRefreshed` |
| `src/Exceptions/` | Custom exceptions: `ShopifyApiException`, `TokenExchangeException` |
| `src/Facades/` | `ShopifyApp` facade (wraps `GraphQLClient`) |
| `src/Http/Controllers/` | Token exchange, webhook, and billing controllers |
| `src/Http/Middleware/` | 5 middleware: VerifyShopify, VerifyBilling, VerifyWebhookHmac, VerifyAppProxy, ShareShopifyInertiaData |
| `src/Models/` | Eloquent models: `Shop`, `Session`, `Plan` |
| `src/Navigation/` | Navigation Bridge for syncing routes with Shopify Admin URL bar |
| `src/Services/` | API clients (GraphQL, REST), BillingService, RateLimiter, WebhookRegistrar |
| `src/Support/` | Utilities: `ShopifyHelper`, `HmacVerifier` |
| `src/Traits/` | `ShopifyRequestContext` trait for controllers |
| `stubs/` | Webhook job stub for code generation |
| `vite-plugin/` | Vite plugin for App Bridge 4 + HMR in iframe |
| `tests/` | PHPUnit tests (Unit + Feature) |

## Key Concepts to Understand First

1. **App Bridge 4** — Shopify's JavaScript SDK that runs inside the Admin iframe. It provides session tokens (JWTs) that your backend uses to identify the shop.

2. **Token Exchange** — Instead of OAuth redirects, your backend exchanges the App Bridge JWT for an access token directly with Shopify's API.

3. **Session Token (JWT)** — A short-lived token (~60 seconds) issued by App Bridge. Contains the shop domain, user info, and is signed by your app's API secret.

4. **Access Token** — A long-lived token returned by Shopify after token exchange. Used to make API calls. Can be offline (permanent) or online (user-scoped).

5. **Leaky Bucket** — Shopify's rate limiting algorithm. This package implements it client-side to avoid hitting 429 errors.

## Recommended Reading Order

1. **02-INSTALLATION.md** — Get the package installed
2. **03-CONFIGURATION.md** — Understand every config option
3. **05-AUTHENTICATION.md** — How token exchange works (the core)
4. **06-MIDDLEWARE.md** — How requests are verified
5. **07-MODELS.md** — The database models
6. **10-SERVICES.md** — Making API calls
7. **11-BILLING.md** — Charging merchants
8. **12-WEBHOOKS.md** — Handling Shopify events
9. **16-VIEWS-AND-FRONTEND.md** — Frontend integration
10. Everything else as needed
