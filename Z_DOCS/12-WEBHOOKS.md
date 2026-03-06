# 12 - Webhooks

Webhooks let Shopify notify your app when events happen (product updated, order created, app uninstalled, etc.). This package handles the entire webhook lifecycle: registration, receiving, verification, and dispatching.

**Source files:**
- `src/Services/WebhookRegistrar.php` — Registers webhooks with Shopify
- `src/Http/Controllers/WebhookController.php` — Receives and dispatches webhooks
- `src/Console/Commands/GenerateWebhookCommand.php` — Scaffolds webhook Job classes
- `stubs/webhook-job.stub` — Job class template

---

## How Webhooks Work in This Package

```
1. Shop installs your app
   → TokenExchange::persistSession() fires
   → WebhookRegistrar::registerAll() called automatically
   → Webhooks registered with Shopify via GraphQL

2. Event happens on the shop (e.g., product updated)
   → Shopify sends POST to /shopify/webhooks
   → WebhookController verifies HMAC
   → WebhookController dispatches the configured Job

3. Job runs in the background (via Laravel queue)
   → Your logic processes the webhook data
```

---

## Step 1: Define Webhooks in Config

In `config/shopify-app.php`:

```php
'webhooks' => [
    'APP_UNINSTALLED' => \App\Jobs\Shopify\AppUninstalledJob::class,
    'PRODUCTS_UPDATE' => \App\Jobs\Shopify\ProductsUpdateJob::class,
    'ORDERS_CREATE' => \App\Jobs\Shopify\OrdersCreateJob::class,
],
```

**Topic format:** Use the GraphQL subscription format with uppercase and underscores (e.g., `PRODUCTS_UPDATE`, not `products/update`).

---

## Step 2: Generate Webhook Job Classes

Use the Artisan command:

```bash
php artisan shopify:generate:webhook PRODUCTS_UPDATE
```

This creates `app/Jobs/Shopify/ProductsUpdateJob.php`:

```php
class ProductsUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $shopDomain;
    public array $data;
    public string $topic;
    public string $apiVersion;

    public function __construct(string $shopDomain, array $data, string $topic, string $apiVersion)
    {
        $this->shopDomain = $shopDomain;
        $this->data = $data;
        $this->topic = $topic;
        $this->apiVersion = $apiVersion;
    }

    public function handle(): void
    {
        // TODO: Implement your webhook handling logic here
    }
}
```

The command also attempts to auto-register the topic in `config/shopify-app.php`.

### Constructor Parameters

Your Job receives exactly these 4 parameters (dispatched by `WebhookController`):

| Parameter | Type | Description |
|---|---|---|
| `$shopDomain` | string | e.g., `my-store.myshopify.com` |
| `$data` | array | The webhook payload (decoded JSON) |
| `$topic` | string | e.g., `products/update` (Shopify's format) |
| `$apiVersion` | string | e.g., `2025-01` |

---

## Step 3: Webhook Registration (`WebhookRegistrar`)

**File:** `src/Services/WebhookRegistrar.php`

### Automatic Registration

When a shop installs your app for the first time, `TokenExchange::persistSession()` calls:

```php
$registrar = app(WebhookRegistrar::class);
$registrar->registerAll($shopDomain, $accessToken);
```

### `registerAll()` Method

Iterates over all topics in config and registers each one:

1. Builds the callback URL: `{app_url}{webhook_path}` → e.g., `https://your-app.com/shopify/webhooks`
2. Calls `register()` for each topic
3. Logs success/failure for each

### `register()` — GraphQL Mutation

```graphql
mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
    webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
        webhookSubscription {
            id
            topic
            endpoint {
                ... on WebhookHttpEndpoint {
                    callbackUrl
                }
            }
        }
        userErrors { field message }
    }
}
```

Variables:
```json
{
    "topic": "PRODUCTS_UPDATE",
    "webhookSubscription": {
        "callbackUrl": "https://your-app.com/shopify/webhooks",
        "format": "JSON"
    }
}
```

### `listAll()` — Query Registered Webhooks

```php
$registrar = app(WebhookRegistrar::class);
$webhooks = $registrar->listAll($shopDomain, $accessToken);
// Returns array of webhook subscription objects
```

### `delete()` — Remove a Webhook

```php
$registrar->delete($shopDomain, $accessToken, 'gid://shopify/WebhookSubscription/123');
```

### Manual Registration

You can register webhooks programmatically:

```php
$registrar = app(WebhookRegistrar::class);
$registrar->register(
    'my-store.myshopify.com',
    'shpat_...',
    'PRODUCTS_UPDATE',
    'https://your-app.com/shopify/webhooks'
);
```

---

## Step 4: Receiving Webhooks (`WebhookController`)

**File:** `src/Http/Controllers/WebhookController.php`
**Route:** `POST /shopify/webhooks`

### What Happens When Shopify Sends a Webhook

1. **HMAC Verification** — The controller computes `base64(hmac_sha256(body, api_secret))` and compares it with the `X-Shopify-Hmac-Sha256` header using `hash_equals()` (timing-safe)

2. **Topic Normalization** — Shopify sends `products/update` but your config uses `PRODUCTS_UPDATE`. The controller normalizes: `strtoupper(str_replace('/', '_', $topic))`

3. **Job Lookup** — Checks config for both normalized and original format

4. **Dispatch** — `dispatch(new $jobClass($shopDomain, $data, $topic, $apiVersion))`

### Response Codes

| Code | Meaning |
|---|---|
| 200 | Webhook processed (or no handler configured — not an error) |
| 401 | Invalid HMAC signature |
| 500 | Job class not found in filesystem |

> **Important:** Return 200 even for unhandled topics. If you return non-2xx, Shopify will retry and eventually disable the webhook.

---

## CSRF Exemption

Webhooks come from Shopify's servers, not from a browser. They don't include a CSRF token, so you **must** exempt the webhook path:

```php
// Laravel 10
protected $except = ['shopify/webhooks'];

// Laravel 11+
$middleware->validateCsrfTokens(except: ['shopify/webhooks']);
```

---

## Common Webhook Topics

| Topic | When It Fires |
|---|---|
| `APP_UNINSTALLED` | Shop uninstalls your app |
| `PRODUCTS_CREATE` | New product created |
| `PRODUCTS_UPDATE` | Product updated |
| `PRODUCTS_DELETE` | Product deleted |
| `ORDERS_CREATE` | New order placed |
| `ORDERS_UPDATED` | Order updated |
| `ORDERS_PAID` | Order payment completed |
| `CUSTOMERS_CREATE` | New customer registered |
| `SHOP_UPDATE` | Shop settings changed |
| `APP_SUBSCRIPTIONS_UPDATE` | Billing subscription changed |

Full list: [Shopify Webhook Topics](https://shopify.dev/docs/api/admin-graphql/latest/enums/WebhookSubscriptionTopic)

---

## Example: Handling APP_UNINSTALLED

```bash
php artisan shopify:generate:webhook APP_UNINSTALLED
```

```php
// app/Jobs/Shopify/AppUninstalledJob.php
class AppUninstalledJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $shopDomain;
    public array $data;
    public string $topic;
    public string $apiVersion;

    public function __construct(string $shopDomain, array $data, string $topic, string $apiVersion)
    {
        $this->shopDomain = $shopDomain;
        $this->data = $data;
        $this->topic = $topic;
        $this->apiVersion = $apiVersion;
    }

    public function handle(): void
    {
        $shop = Shop::where('shop_domain', $this->shopDomain)->first();

        if ($shop) {
            $shop->update([
                'is_installed' => false,
                'access_token' => null,
                'refresh_token' => null,
                'uninstalled_at' => now(),
            ]);

            // Clean up sessions
            Session::where('shop_domain', $this->shopDomain)->delete();

            // Cancel active plans
            Plan::where('shop_domain', $this->shopDomain)
                ->where('status', 'active')
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            // Fire event for your app to listen to
            event(new ShopUninstalled($shop));
        }
    }
}
```

---

## The Webhook Job Stub

**File:** `stubs/webhook-job.stub`

This is the template used by `shopify:generate:webhook`. It uses `{{ className }}` and `{{ topic }}` placeholders that get replaced during generation.

You can customize this stub by publishing it:

```bash
php artisan vendor:publish --tag=shopify-stubs
```

Then edit `stubs/shopify/webhook-job.stub` in your project root.
