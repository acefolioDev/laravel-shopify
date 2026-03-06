# 13 - Events

The package dispatches Laravel events at key points in the app lifecycle. You can listen for these events to run your own logic.

**Source files:** `src/Events/`

---

## Available Events

| Event | When It Fires | Fired By |
|---|---|---|
| `ShopInstalled` | First-time token exchange for a new shop | `TokenExchange::persistSession()` |
| `ShopTokenRefreshed` | Token refreshed/re-exchanged for an existing shop | `TokenExchange::persistSession()` |
| `ShopUninstalled` | **You dispatch this** in your `APP_UNINSTALLED` webhook Job | Your code |

---

## Event Classes

All three events have the same structure:

```php
namespace LaravelShopify\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelShopify\Models\Shop;

class ShopInstalled  // or ShopTokenRefreshed, ShopUninstalled
{
    use Dispatchable, SerializesModels;

    public Shop $shop;

    public function __construct(Shop $shop)
    {
        $this->shop = $shop;
    }
}
```

Each event carries the `Shop` Eloquent model as a public property.

---

## 1. `ShopInstalled`

Fired when a shop installs your app **for the first time**. Specifically, it fires when:
- `TokenExchange::persistSession()` runs
- No existing `Shop` record has `is_installed = true` for this domain
- The token exchange is for an **offline** session (not online)

**This is where you should:**
- Set up default data for the new shop
- Send a welcome email
- Create default settings
- Sync initial data from Shopify

### When It Does NOT Fire

- If the shop re-installs (they uninstalled and installed again, but the DB record still exists with `is_installed = false` — note: `updateOrCreate` sets it back to `true`, so this fires again)
- Online token exchanges (only offline triggers install events)
- Token refreshes (that fires `ShopTokenRefreshed` instead)

---

## 2. `ShopTokenRefreshed`

Fired when a token is refreshed or re-exchanged for an **already installed** shop. This happens:
- When the offline token expires and gets refreshed
- When the shop's scopes change and a new token is exchanged
- On every token exchange for a shop that's already installed

**This is where you could:**
- Log token refresh activity
- Update shop metadata
- Re-sync data if scopes changed

---

## 3. `ShopUninstalled`

This event is **NOT automatically fired** by the package. You must dispatch it yourself in your `APP_UNINSTALLED` webhook Job:

```php
// app/Jobs/Shopify/AppUninstalledJob.php
public function handle(): void
{
    $shop = Shop::where('shop_domain', $this->shopDomain)->first();

    if ($shop) {
        $shop->update([
            'is_installed' => false,
            'access_token' => null,
            'uninstalled_at' => now(),
        ]);

        event(new \LaravelShopify\Events\ShopUninstalled($shop));
    }
}
```

**This is where you should:**
- Clean up shop-specific data
- Cancel background tasks
- Remove cached data

---

## Registering Event Listeners

### Option 1: EventServiceProvider (Laravel 10)

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    \LaravelShopify\Events\ShopInstalled::class => [
        \App\Listeners\SetupNewShop::class,
        \App\Listeners\SendWelcomeEmail::class,
    ],
    \LaravelShopify\Events\ShopTokenRefreshed::class => [
        \App\Listeners\LogTokenRefresh::class,
    ],
    \LaravelShopify\Events\ShopUninstalled::class => [
        \App\Listeners\CleanupShopData::class,
    ],
];
```

### Option 2: Event::listen (Laravel 11+)

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\Event;

public function boot(): void
{
    Event::listen(
        \LaravelShopify\Events\ShopInstalled::class,
        function ($event) {
            // $event->shop is the Shop model
            logger()->info('New shop installed: ' . $event->shop->shop_domain);
        }
    );
}
```

### Option 3: Listener Class

```bash
php artisan make:listener SetupNewShop --event=\\LaravelShopify\\Events\\ShopInstalled
```

```php
// app/Listeners/SetupNewShop.php
namespace App\Listeners;

use LaravelShopify\Events\ShopInstalled;

class SetupNewShop
{
    public function handle(ShopInstalled $event): void
    {
        $shop = $event->shop;

        // Create default settings for the new shop
        ShopSetting::create([
            'shop_domain' => $shop->shop_domain,
            'theme' => 'default',
            'notifications_enabled' => true,
        ]);

        // Sync products from Shopify
        SyncProductsJob::dispatch($shop->shop_domain, $shop->access_token);
    }
}
```

---

## Event Dispatch Timing

Understanding when events fire in the token exchange flow:

```
TokenExchange::persistSession()
    │
    ├── Is this a NEW install? (no Shop with is_installed=true)
    │   └── YES + offline token
    │       ├── event(new ShopInstalled($shop))  ← FIRES HERE
    │       └── registerWebhooks()
    │
    ├── Is this an EXISTING install?
    │   └── YES + offline token
    │       └── event(new ShopTokenRefreshed($shop))  ← FIRES HERE
    │
    └── Online token exchange?
        └── No events fired
```

---

## Important Notes

- Events fire **synchronously** by default. If your listener does heavy work, make it implement `ShouldQueue`.
- The `Shop` model passed to events has the **latest data** (just updated by `updateOrCreate`).
- `ShopInstalled` also triggers webhook registration — this happens AFTER the event fires.
