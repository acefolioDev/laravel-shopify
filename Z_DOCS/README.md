# Laravel Shopify Package — Documentation Index

Welcome! This folder contains **20 detailed README files**, each covering one aspect of the `acefolio/laravel-shopify` package. Start with the overview and follow the recommended reading order.

---

## Recommended Reading Order (Start Here)

| # | File | Topic | Priority |
|---|---|---|---|
| 01 | [01-OVERVIEW.md](./01-OVERVIEW.md) | Package overview, architecture diagram, key concepts | Start here |
| 02 | [02-INSTALLATION.md](./02-INSTALLATION.md) | Installation, publishing, environment setup | Setup |
| 03 | [03-CONFIGURATION.md](./03-CONFIGURATION.md) | Every config option in `shopify-app.php` explained | Setup |
| 05 | [05-AUTHENTICATION.md](./05-AUTHENTICATION.md) | Token Exchange & Session Token — the core of the package | Core |
| 06 | [06-MIDDLEWARE.md](./06-MIDDLEWARE.md) | All 5 middleware classes explained | Core |
| 07 | [07-MODELS.md](./07-MODELS.md) | Shop, Session, Plan — Eloquent models | Core |
| 10 | [10-SERVICES.md](./10-SERVICES.md) | GraphQL client, REST client, Rate Limiter | Core |
| 11 | [11-BILLING.md](./11-BILLING.md) | Billing system — charges, subscriptions, callbacks | Feature |
| 12 | [12-WEBHOOKS.md](./12-WEBHOOKS.md) | Webhook registration, receiving, and dispatching | Feature |
| 16 | [16-VIEWS-AND-FRONTEND.md](./16-VIEWS-AND-FRONTEND.md) | Blade layouts, Vite plugin, App Bridge 4 | Frontend |

---

## Complete File List

| # | File | What You'll Learn |
|---|---|---|
| 01 | [01-OVERVIEW.md](./01-OVERVIEW.md) | What the package does, architecture diagram, directory structure, key concepts |
| 02 | [02-INSTALLATION.md](./02-INSTALLATION.md) | Composer install, publishing assets, migrations, env setup, CSRF exemption |
| 03 | [03-CONFIGURATION.md](./03-CONFIGURATION.md) | Every option in `config/shopify-app.php` with explanations |
| 04 | [04-SERVICE-PROVIDER.md](./04-SERVICE-PROVIDER.md) | How `ShopifyAppServiceProvider` wires everything together |
| 05 | [05-AUTHENTICATION.md](./05-AUTHENTICATION.md) | SessionToken JWT validation, TokenExchange flow, session persistence |
| 06 | [06-MIDDLEWARE.md](./06-MIDDLEWARE.md) | VerifyShopify, VerifyBilling, VerifyWebhookHmac, VerifyAppProxy, ShareShopifyInertiaData |
| 07 | [07-MODELS.md](./07-MODELS.md) | Shop, Session, Plan models — fields, relationships, scopes, methods |
| 08 | [08-ROUTES.md](./08-ROUTES.md) | 3 package routes: token exchange, webhooks, billing callback |
| 09 | [09-CONTROLLERS.md](./09-CONTROLLERS.md) | TokenExchangeController, WebhookController, BillingController |
| 10 | [10-SERVICES.md](./10-SERVICES.md) | GraphQLClient, ShopifyApiClient, RateLimiter (leaky bucket) |
| 11 | [11-BILLING.md](./11-BILLING.md) | BillingService, charge creation, confirmation, plan lifecycle |
| 12 | [12-WEBHOOKS.md](./12-WEBHOOKS.md) | WebhookRegistrar, WebhookController, generating webhook Jobs |
| 13 | [13-EVENTS.md](./13-EVENTS.md) | ShopInstalled, ShopUninstalled, ShopTokenRefreshed events |
| 14 | [14-HELPERS.md](./14-HELPERS.md) | ShopifyHelper (URLs, domains), HmacVerifier, ShopifyRequestContext trait |
| 15 | [15-ARTISAN-COMMANDS.md](./15-ARTISAN-COMMANDS.md) | shopify:app:dev, shopify:app:deploy, shopify:generate:webhook, shopify:generate:extension |
| 16 | [16-VIEWS-AND-FRONTEND.md](./16-VIEWS-AND-FRONTEND.md) | Blade layouts, Vite plugin, App Bridge 4, authenticatedFetch |
| 17 | [17-TESTING.md](./17-TESTING.md) | Test suite overview, TestCase setup, all unit & feature tests explained |
| 18 | [18-FACADE.md](./18-FACADE.md) | ShopifyApp facade — static interface to GraphQLClient |
| 19 | [19-DATABASE.md](./19-DATABASE.md) | 3 migrations, full schema, ER diagram, security notes |
| 20 | [20-NAVIGATION.md](./20-NAVIGATION.md) | NavigationBridge — sync routes with Shopify Admin URL bar |

---

## Quick Reference

### The 3 Most Important Files to Read

1. **[05-AUTHENTICATION.md](./05-AUTHENTICATION.md)** — Understand how Token Exchange works (the heart of the package)
2. **[06-MIDDLEWARE.md](./06-MIDDLEWARE.md)** — Understand how requests are verified and shop context is set
3. **[10-SERVICES.md](./10-SERVICES.md)** — Understand how to make API calls to Shopify

### Looking for Something Specific?

- **"How do I make API calls?"** → [10-SERVICES.md](./10-SERVICES.md)
- **"How does authentication work?"** → [05-AUTHENTICATION.md](./05-AUTHENTICATION.md)
- **"How do I charge merchants?"** → [11-BILLING.md](./11-BILLING.md)
- **"How do I handle webhooks?"** → [12-WEBHOOKS.md](./12-WEBHOOKS.md)
- **"How do I set up my frontend?"** → [16-VIEWS-AND-FRONTEND.md](./16-VIEWS-AND-FRONTEND.md)
- **"How do I start developing?"** → [15-ARTISAN-COMMANDS.md](./15-ARTISAN-COMMANDS.md) (shopify:app:dev)
- **"What's in the database?"** → [19-DATABASE.md](./19-DATABASE.md)
- **"How do I run tests?"** → [17-TESTING.md](./17-TESTING.md)
