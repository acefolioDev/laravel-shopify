# 07 - Eloquent Models (Shop, Session, Plan)

The package uses 3 Eloquent models to persist Shopify data. All models use `$guarded = ['id']` (mass-assignable except `id`).

**Source files:** `src/Models/`

---

## 1. `Shop` Model

**File:** `src/Models/Shop.php`
**Table:** `shopify_shops` (configurable via `config('shopify-app.tables.shops')`)

The `Shop` model represents a Shopify store that has installed your app.

### Fields

| Column | Type | Description |
|---|---|---|
| `id` | bigint | Auto-increment primary key |
| `shop_domain` | string | **Unique, indexed.** e.g., `my-store.myshopify.com` |
| `shop_name` | string (nullable) | Store display name |
| `email` | string (nullable) | Store owner email |
| `access_token` | string (nullable) | Current offline access token |
| `refresh_token` | string (nullable) | Refresh token for token rotation |
| `token_expires_at` | datetime (nullable) | When the access token expires |
| `scopes` | string (nullable) | Granted scopes, comma-separated |
| `plan_name` | string (nullable) | Shopify plan name (Basic, Plus, etc.) |
| `shop_owner` | string (nullable) | Owner name |
| `country` | string (nullable) | Store country |
| `currency` | string (nullable) | Store currency |
| `timezone` | string (nullable) | Store timezone |
| `is_installed` | boolean | `true` if app is currently installed |
| `is_freemium` | boolean | For freemium logic if needed |
| `installed_at` | datetime (nullable) | First install timestamp |
| `uninstalled_at` | datetime (nullable) | When app was uninstalled |
| `metadata` | JSON (nullable) | Flexible JSON for custom data |
| `created_at` | datetime | Laravel timestamp |
| `updated_at` | datetime | Laravel timestamp |

### Casts

```php
protected $casts = [
    'is_installed' => 'boolean',
    'is_freemium' => 'boolean',
    'token_expires_at' => 'datetime',
    'installed_at' => 'datetime',
    'uninstalled_at' => 'datetime',
    'metadata' => 'array',
];
```

### Relationships

```php
$shop->sessions        // HasMany Session (all sessions for this shop)
$shop->offlineSession  // HasOne Session (latest offline session)
$shop->plans           // HasMany Plan (all billing plans)
$shop->activePlan      // HasOne Plan (latest active plan)
```

### Key Methods

**`isTokenExpired(): bool`**
Checks if the access token is expired (or will expire within the buffer window). Uses the `refresh_buffer_seconds` config value (default 300 seconds / 5 minutes).

```php
if ($shop->isTokenExpired()) {
    // Token needs refresh
}
```

**`needsReauth(): bool`**
Returns `true` if:
1. No access token exists, OR
2. Token is expired (with buffer), OR
3. Required scopes don't match granted scopes

This is called by `TokenExchange::ensureOfflineSession()` to decide whether to re-exchange.

### Scopes

```php
Shop::installed()->get();              // Only installed shops
Shop::domain('my-store.myshopify.com')->first(); // Find by domain
```

---

## 2. `Session` Model

**File:** `src/Models/Session.php`
**Table:** `shopify_sessions` (configurable via `config('shopify-app.tables.sessions')`)

Stores access token sessions. A shop can have multiple sessions (one offline, multiple online for different users).

### Fields

| Column | Type | Description |
|---|---|---|
| `id` | bigint | Auto-increment primary key |
| `session_id` | string | **Unique, indexed.** Format: `offline_{shop}` or `online_{shop}_{userId}` |
| `shop_domain` | string | **Indexed.** The shop this session belongs to |
| `access_token` | string (nullable) | The Shopify access token (**hidden from serialization**) |
| `refresh_token` | string (nullable) | Refresh token (**hidden from serialization**) |
| `scope` | string (nullable) | Granted scopes |
| `expires_at` | datetime (nullable) | Token expiration time |
| `is_online` | boolean | `false` for offline, `true` for online |
| `online_access_info` | string (nullable) | Raw online access info |
| `user_id` | bigint (nullable) | Shopify user ID (online tokens only) |
| `user_first_name` | string (nullable) | User's first name |
| `user_last_name` | string (nullable) | User's last name |
| `user_email` | string (nullable) | User's email |
| `user_email_verified` | boolean (nullable) | Email verification status |
| `account_owner` | boolean (nullable) | Whether user is the store owner |
| `locale` | string (nullable) | User's locale |
| `collaborator` | string (nullable) | Collaborator flag |
| `associated_user` | JSON (nullable) | Full user data from Shopify |

### Hidden Fields

```php
protected $hidden = ['access_token', 'refresh_token'];
```

This prevents tokens from being leaked when the model is serialized to JSON (e.g., in API responses).

### Key Methods

**`isExpired(): bool`** — Returns `true` if `expires_at` is in the past

**`isValid(): bool`** — Returns `true` if access token exists AND is not expired

### Scopes

```php
Session::forShop('my-store.myshopify.com')->get();  // Sessions for a shop
Session::offline()->get();                            // Only offline sessions
Session::online()->get();                             // Only online sessions
Session::valid()->get();                              // Non-expired with access token

// Chaining
Session::forShop($domain)->offline()->valid()->first();
```

### Relationship

```php
$session->shop  // BelongsTo Shop
```

### Composite Index

The migration creates a composite index on `['shop_domain', 'is_online']` for fast lookups.

---

## 3. `Plan` Model

**File:** `src/Models/Plan.php`
**Table:** `shopify_plans` (configurable via `config('shopify-app.tables.plans')`)

Stores billing plan subscriptions for shops.

### Fields

| Column | Type | Description |
|---|---|---|
| `id` | bigint | Auto-increment primary key |
| `shop_domain` | string | **Indexed.** The shop this plan belongs to |
| `plan_slug` | string | Matches the key in config (e.g., `basic`, `pro`) |
| `plan_name` | string | Display name (e.g., `Basic Plan`) |
| `type` | string | `recurring` or `one_time` |
| `price` | decimal(10,2) | Plan price |
| `currency` | string(3) | Currency code (default `USD`) |
| `interval` | string (nullable) | `EVERY_30_DAYS` or `ANNUAL` |
| `trial_days` | integer | Trial period in days (default 0) |
| `capped_amount` | decimal(10,2) (nullable) | Max usage charge |
| `terms` | string (nullable) | Usage charge terms |
| `test` | boolean | Whether this is a test charge |
| `charge_id` | string (nullable) | **Indexed.** Shopify's charge GID |
| `status` | string | `pending`, `active`, `declined`, `expired`, `cancelled` |
| `activated_at` | datetime (nullable) | When the plan was activated |
| `cancelled_at` | datetime (nullable) | When the plan was cancelled |
| `trial_ends_at` | datetime (nullable) | When the trial ends |

### Key Methods

```php
$plan->isActive()    // status === 'active'
$plan->isRecurring() // type === 'recurring'
$plan->isOneTime()   // type === 'one_time'
$plan->isInTrial()   // trial_ends_at is in the future
```

### Scopes

```php
Plan::active()->get();                                // Active plans only
Plan::forShop('my-store.myshopify.com')->get();      // Plans for a shop
Plan::forShop($domain)->active()->first();            // Active plan for a shop
```

### Relationship

```php
$plan->shop  // BelongsTo Shop
```

### Composite Index

The migration creates a composite index on `['shop_domain', 'status']` for fast billing lookups.

---

## How Models Are Used Internally

| Where | Model | Operation |
|---|---|---|
| `TokenExchange::persistSession()` | Shop | `updateOrCreate` on install/refresh |
| `TokenExchange::persistSession()` | Session | `updateOrCreate` on install/refresh |
| `VerifyShopify` middleware | Session | Read via `TokenExchange::ensureSession()` |
| `VerifyBilling` middleware | Plan | `Plan::forShop()->active()->first()` |
| `BillingService::createCharge()` | Plan | `updateOrCreate` with status `pending` |
| `BillingService::confirmCharge()` | Plan | Update to `active`, cancel old plans |
