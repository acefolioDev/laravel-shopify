# 19 - Database (Migrations & Schema)

The package creates 3 database tables to store shop, session, and billing data. Migrations run automatically — publishing is optional.

**Source files:** `database/migrations/`

---

## Migration Files

| File | Table | Purpose |
|---|---|---|
| `2024_01_01_000001_create_shopify_shops_table.php` | `shopify_shops` | Store info & access tokens |
| `2024_01_01_000002_create_shopify_sessions_table.php` | `shopify_sessions` | Session tokens (offline + online) |
| `2024_01_01_000003_create_shopify_plans_table.php` | `shopify_plans` | Billing plan subscriptions |

### Migration Loading

Migrations are loaded automatically by the service provider:

```php
$this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
```

You do NOT need to publish them. Just run `php artisan migrate`.

To customize the migrations, publish them first:

```bash
php artisan vendor:publish --tag=shopify-migrations
```

### Custom Table Names

All models read their table name from config:

```php
// config/shopify-app.php
'tables' => [
    'shops' => 'shopify_shops',
    'sessions' => 'shopify_sessions',
    'plans' => 'shopify_plans',
],
```

If you change these, you must also update the migration files (after publishing them).

---

## Table 1: `shopify_shops`

Stores information about each Shopify store that has installed your app.

### Schema

```sql
CREATE TABLE shopify_shops (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_domain     VARCHAR(255) NOT NULL UNIQUE,    -- indexed
    shop_name       VARCHAR(255) NULL,
    email           VARCHAR(255) NULL,
    access_token    VARCHAR(255) NULL,
    refresh_token   VARCHAR(255) NULL,
    token_expires_at TIMESTAMP NULL,
    scopes          VARCHAR(255) NULL,
    plan_name       VARCHAR(255) NULL,
    shop_owner      VARCHAR(255) NULL,
    country         VARCHAR(255) NULL,
    currency        VARCHAR(255) NULL,
    timezone        VARCHAR(255) NULL,
    is_installed    BOOLEAN DEFAULT FALSE,
    is_freemium     BOOLEAN DEFAULT FALSE,
    installed_at    TIMESTAMP NULL,
    uninstalled_at  TIMESTAMP NULL,
    metadata        JSON NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL
);
```

### Indexes

- **`shop_domain`** — Unique index (also the primary lookup field)

### Key Columns Explained

| Column | Set By | When |
|---|---|---|
| `shop_domain` | `TokenExchange::persistSession()` | First token exchange |
| `access_token` | `TokenExchange::persistSession()` | Every token exchange/refresh |
| `refresh_token` | `TokenExchange::persistSession()` | Token exchange (if Shopify provides one) |
| `token_expires_at` | `TokenExchange::persistSession()` | Calculated from `expires_in` response |
| `scopes` | `TokenExchange::persistSession()` | From token exchange response |
| `is_installed` | `TokenExchange::persistSession()` | Set to `true` on install |
| `installed_at` | `TokenExchange::persistSession()` | Set to `now()` on install |
| `metadata` | Your app code | Flexible JSON for any custom data |

### Lifecycle

```
Install:     → updateOrCreate with is_installed=true, access_token=..., installed_at=now()
Re-install:  → updateOrCreate updates access_token, keeps shop_domain
Uninstall:   → Your webhook job sets is_installed=false, access_token=null, uninstalled_at=now()
Token refresh: → updateOrCreate updates access_token, refresh_token, token_expires_at
```

---

## Table 2: `shopify_sessions`

Stores access token sessions. Each shop can have one offline session and multiple online sessions (one per user).

### Schema

```sql
CREATE TABLE shopify_sessions (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id          VARCHAR(255) NOT NULL UNIQUE,    -- indexed
    shop_domain         VARCHAR(255) NOT NULL,           -- indexed
    access_token        VARCHAR(255) NULL,
    refresh_token       VARCHAR(255) NULL,
    scope               VARCHAR(255) NULL,
    expires_at          TIMESTAMP NULL,
    is_online           BOOLEAN DEFAULT FALSE,
    online_access_info  VARCHAR(255) NULL,
    user_id             BIGINT UNSIGNED NULL,
    user_first_name     VARCHAR(255) NULL,
    user_last_name      VARCHAR(255) NULL,
    user_email          VARCHAR(255) NULL,
    user_email_verified BOOLEAN NULL,
    account_owner       BOOLEAN NULL,
    locale              VARCHAR(255) NULL,
    collaborator        VARCHAR(255) NULL,
    associated_user     JSON NULL,
    created_at          TIMESTAMP NULL,
    updated_at          TIMESTAMP NULL
);
```

### Indexes

- **`session_id`** — Unique index
- **`shop_domain`** — Standard index
- **`(shop_domain, is_online)`** — Composite index for fast lookups

### Session ID Format

| Mode | Format | Example |
|---|---|---|
| Offline | `offline_{shop_domain}` | `offline_my-store.myshopify.com` |
| Online | `online_{shop_domain}_{user_id}` | `online_my-store.myshopify.com_12345` |

### Online Session User Fields

When an online token is exchanged, Shopify returns user info:

| Column | Source | Example |
|---|---|---|
| `user_id` | `associated_user.id` | `12345` |
| `user_first_name` | `associated_user.first_name` | `John` |
| `user_last_name` | `associated_user.last_name` | `Doe` |
| `user_email` | `associated_user.email` | `john@shop.com` |
| `user_email_verified` | `associated_user.email_verified` | `true` |
| `account_owner` | `associated_user.account_owner` | `true` |
| `locale` | `associated_user.locale` | `en` |
| `collaborator` | `associated_user.collaborator` | `false` |
| `associated_user` | Full JSON object | `{...}` |

---

## Table 3: `shopify_plans`

Stores billing plan subscriptions.

### Schema

```sql
CREATE TABLE shopify_plans (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_domain     VARCHAR(255) NOT NULL,           -- indexed
    plan_slug       VARCHAR(255) NOT NULL,
    plan_name       VARCHAR(255) NOT NULL,
    type            VARCHAR(255) NOT NULL,           -- 'recurring' or 'one_time'
    price           DECIMAL(10,2) NOT NULL,
    currency        VARCHAR(3) DEFAULT 'USD',
    interval        VARCHAR(255) NULL,               -- EVERY_30_DAYS, ANNUAL
    trial_days      INTEGER DEFAULT 0,
    capped_amount   DECIMAL(10,2) NULL,
    terms           VARCHAR(255) NULL,
    test            BOOLEAN DEFAULT FALSE,
    charge_id       VARCHAR(255) NULL,               -- indexed
    status          VARCHAR(255) DEFAULT 'pending',  -- pending/active/declined/expired/cancelled
    activated_at    TIMESTAMP NULL,
    cancelled_at    TIMESTAMP NULL,
    trial_ends_at   TIMESTAMP NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL
);
```

### Indexes

- **`shop_domain`** — Standard index
- **`charge_id`** — Standard index (for Shopify GID lookups)
- **`(shop_domain, status)`** — Composite index for billing checks

### Plan Status Lifecycle

```
pending  → Charge created, waiting for merchant to approve
active   → Merchant approved, charge is active
declined → Merchant declined the charge
expired  → Charge timed out (not approved in time)
cancelled → Plan was cancelled (by your app or another plan replaced it)
```

### How Plans Flow Through Status

```
1. BillingService::createCharge()
   → Creates Plan with status='pending'

2. Merchant approves on Shopify checkout
   → Shopify redirects to /shopify/billing/callback

3. BillingController::callback()
   → Calls BillingService::confirmCharge()
   → Old active plans → status='cancelled'
   → This plan → status='active', activated_at=now()
```

---

## Entity-Relationship Diagram

```
┌──────────────────┐     ┌────────────────────┐
│  shopify_shops   │     │  shopify_sessions   │
│──────────────────│     │────────────────────│
│ id (PK)          │     │ id (PK)            │
│ shop_domain (UQ) │────►│ shop_domain (FK)   │
│ access_token     │     │ session_id (UQ)    │
│ refresh_token    │     │ access_token       │
│ is_installed     │     │ is_online          │
│ ...              │     │ user_id            │
└──────────────────┘     │ ...                │
        │                └────────────────────┘
        │
        │                ┌────────────────────┐
        │                │  shopify_plans      │
        │                │────────────────────│
        │                │ id (PK)            │
        └───────────────►│ shop_domain (FK)   │
                         │ plan_slug          │
                         │ status             │
                         │ charge_id          │
                         │ ...                │
                         └────────────────────┘
```

**Note:** The foreign key relationship is based on `shop_domain` string matching, not a traditional integer FK. This is by design — it makes the package work without tight coupling to the shops table.

---

## Security Considerations

- **`access_token` and `refresh_token`** are stored in plain text in the database. Consider encrypting these columns at the application level if your security requirements demand it.
- The `Session` model has `protected $hidden = ['access_token', 'refresh_token']` to prevent accidental exposure in JSON responses.
- The `Shop` model does NOT hide `access_token` — be careful not to serialize Shop models in API responses.
