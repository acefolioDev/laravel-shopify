# 17 - Testing

The package includes a comprehensive test suite using PHPUnit and Orchestra Testbench. Understanding the tests helps you learn the package AND shows you how to test your own Shopify app code.

**Source files:**
- `tests/TestCase.php` — Base test class
- `tests/Unit/` — Unit tests (4 files)
- `tests/Feature/` — Feature tests (4 files)

---

## Test Setup (`TestCase.php`)

**File:** `tests/TestCase.php`

The base test class extends `Orchestra\Testbench\TestCase`, which provides a full Laravel application context for package testing.

### What It Configures

```php
// Loads the package's service provider
protected function getPackageProviders($app): array {
    return [ShopifyAppServiceProvider::class];
}

// Registers the facade alias
protected function getPackageAliases($app): array {
    return ['ShopifyApp' => ShopifyApp::class];
}

// Sets up test environment
protected function defineEnvironment($app): void {
    // SQLite in-memory database
    $app['config']->set('database.default', 'testing');
    $app['config']->set('database.connections.testing', [
        'driver' => 'sqlite', 'database' => ':memory:',
    ]);

    // Test Shopify credentials
    $app['config']->set('shopify-app.api_key', 'test-api-key');
    $app['config']->set('shopify-app.api_secret', 'test-api-secret');
    $app['config']->set('shopify-app.scopes', 'read_products,write_products');
    $app['config']->set('shopify-app.app_url', 'https://test-app.example.com');

    // Test billing config with two plans
    $app['config']->set('shopify-app.billing.plans', [
        'basic' => [...],
        'lifetime' => [...],
    ]);
}

// Loads package migrations
protected function defineDatabaseMigrations(): void {
    $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
}
```

### `makeSessionToken()` Helper

Generates valid JWTs for testing:

```php
$jwt = $this->makeSessionToken(
    shop: 'test-store.myshopify.com',
    sub: '12345',          // Optional: user ID for online tokens
    exp: time() + 60,      // Optional: custom expiration
    aud: 'custom-api-key', // Optional: custom audience
);
```

Creates a properly signed HS256 JWT with all required Shopify claims (`iss`, `dest`, `aud`, `exp`, `nbf`, `jti`, `sid`).

Also defines a standalone `base64url_encode()` function used for JWT encoding (URL-safe base64 without padding).

---

## Unit Tests

### `SessionTokenTest` — JWT Validation

**File:** `tests/Unit/SessionTokenTest.php`

Tests the `SessionToken` class:

| Test | What It Verifies |
|---|---|
| `test_extract_token_from_bearer_header` | Extracts JWT from `Authorization: Bearer` header |
| `test_extract_token_from_query_param` | Falls back to `id_token` query parameter |
| `test_returns_null_when_no_token` | Returns `null` when no token present |
| `test_bearer_header_takes_precedence_over_query` | Bearer header wins over query param |
| `test_decode_valid_token` | Decodes a valid JWT and checks claims |
| `test_decode_rejects_expired_token` | Throws exception for expired JWT |
| `test_decode_rejects_wrong_audience` | Throws exception if `aud` doesn't match API key |
| `test_decode_rejects_invalid_signature` | Throws exception for tampered JWT |
| `test_get_shop_domain` | Extracts shop domain from `dest` claim |
| `test_get_session_id_offline` | Generates offline session ID format |
| `test_get_session_id_online` | Generates online session ID with user ID |
| `test_is_online_with_sub` | Detects online token by `sub` claim |
| `test_is_offline_without_sub` | Detects offline token by missing `sub` |

### `HmacVerifierTest` — HMAC Verification

**File:** `tests/Unit/HmacVerifierTest.php`

| Test | What It Verifies |
|---|---|
| `test_verify_webhook_with_valid_hmac` | Valid webhook HMAC passes |
| `test_verify_webhook_with_invalid_hmac` | Invalid HMAC fails |
| `test_verify_webhook_with_empty_secret` | Empty secret returns false |
| `test_verify_webhook_with_empty_hmac` | Empty HMAC returns false |
| `test_verify_proxy_with_valid_signature` | Valid app proxy signature passes |
| `test_verify_proxy_without_signature` | Missing signature returns false |
| `test_verify_oauth_with_valid_hmac` | Valid OAuth HMAC passes |
| `test_verify_oauth_without_hmac` | Missing HMAC returns false |
| `test_valid_shop_domains` | Accepts valid `*.myshopify.com` domains |
| `test_invalid_shop_domains` | Rejects invalid domains |

### `RateLimiterTest` — Leaky Bucket

**File:** `tests/Unit/RateLimiterTest.php`

| Test | What It Verifies |
|---|---|
| `test_throttle_allows_requests_within_bucket` | No sleep for first request |
| `test_throttle_sleeps_when_bucket_full` | Sleeps when bucket overflows |
| `test_update_from_response` | Updates bucket from API response data |
| `test_reset_clears_shop_bucket` | Reset allows immediate requests |
| `test_per_shop_isolation` | Different shops have separate buckets |

### `ShopifyHelperTest` — Domain & URL Utilities

**File:** `tests/Unit/ShopifyHelperTest.php`

| Test | What It Verifies |
|---|---|
| `test_sanitize_plain_name` | `my-store` → `my-store.myshopify.com` |
| `test_sanitize_full_domain` | Already-complete domain passes through |
| `test_sanitize_with_protocol` | Strips `https://` |
| `test_sanitize_with_path` | Strips `/admin` path |
| `test_sanitize_uppercase` | Lowercases domain |
| `test_sanitize_empty_returns_null` | Empty string → `null` |
| `test_sanitize_invalid_returns_null` | Invalid domain → `null` |
| `test_admin_url` / `test_admin_url_with_path` | Builds admin URLs |
| `test_graphql_url` | Builds GraphQL API URLs |
| `test_rest_url` | Builds REST API URLs |
| `test_decode_host` | Decodes base64 host parameter |
| `test_shop_from_host_new_admin_format` | Extracts shop from new admin URL |
| `test_shop_from_host_legacy_format` | Extracts shop from legacy URL |
| `test_shop_from_host_invalid` | Returns null for invalid host |
| `test_embedded_app_url` / `test_embedded_app_url_with_path` | Builds embedded app URLs |

---

## Feature Tests

### `MiddlewareTest` — Middleware Integration

**File:** `tests/Feature/MiddlewareTest.php`

Uses `RefreshDatabase` trait for clean database state.

| Test | What It Verifies |
|---|---|
| `test_verify_shopify_rejects_missing_token` | 401 without Bearer header |
| `test_verify_shopify_rejects_invalid_jwt` | 401 with invalid JWT |
| `test_verify_webhook_hmac_accepts_valid` | Valid HMAC passes through |
| `test_verify_webhook_hmac_rejects_invalid` | Invalid HMAC returns 401 |
| `test_verify_billing_passes_when_not_required` | Billing disabled → pass through |
| `test_verify_billing_rejects_without_shop_domain` | 403 without shop context |
| `test_verify_billing_passes_with_active_plan` | Active plan → pass through, sets `shopify_plan` attribute |
| `test_verify_app_proxy_rejects_invalid_signature` | Invalid proxy signature → 401 |

### `ControllerTest` — Controller Integration

**File:** `tests/Feature/ControllerTest.php`

| Test | What It Verifies |
|---|---|
| `test_webhook_endpoint_rejects_invalid_hmac` | `/shopify/webhooks` returns 401 for bad HMAC |
| `test_webhook_endpoint_accepts_valid_hmac` | `/shopify/webhooks` returns 200 for valid HMAC |
| `test_token_exchange_rejects_missing_token` | `/shopify/auth/token` returns 401 without token |
| `test_billing_callback_rejects_missing_params` | `/shopify/billing/callback` needs shop, plan, charge_id |
| `test_billing_callback_with_all_params` | Routes correctly with all params (fails at DB level) |

### `ModelTest` — Eloquent Model Integration

**File:** `tests/Feature/ModelTest.php`

Comprehensive tests for all three models:

| Test | What It Verifies |
|---|---|
| `test_create_shop` | Creates shop, checks database |
| `test_shop_needs_reauth_without_token` | No token → needs reauth |
| `test_shop_needs_reauth_with_expired_token` | Expired token → needs reauth |
| `test_shop_does_not_need_reauth_with_valid_token` | Valid token → no reauth |
| `test_shop_needs_reauth_with_insufficient_scopes` | Missing scopes → needs reauth |
| `test_shop_has_sessions` | HasMany + HasOne relationships work |
| `test_session_scopes` | `valid()`, `offline()` scopes work correctly |
| `test_session_is_valid` | `isValid()` checks token + expiry |
| `test_create_plan` | Creates plan, checks `isActive()`, `isRecurring()`, `isInTrial()` |
| `test_plan_scopes` | `active()`, `forShop()` scopes work |
| `test_shop_has_active_plan` | `activePlan` relationship works |
| `test_installed_scope` | `installed()` scope filters correctly |

### `CommandTest` — Artisan Command Tests

**File:** `tests/Feature/CommandTest.php`

| Test | What It Verifies |
|---|---|
| `test_generate_webhook_command_creates_job` | Creates Job file with correct class name and traits |
| `test_generate_theme_extension_creates_files` | Creates all theme extension files |
| `test_generate_ui_extension_creates_files` | Creates all UI extension files |
| `test_deploy_command_validates_environment` | Passes validation with valid env |
| `test_deploy_command_fails_with_missing_env` | Fails when API key/secret missing |

All command tests clean up generated files after running.

---

## Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run a specific test file
./vendor/bin/phpunit tests/Unit/SessionTokenTest.php

# Run a specific test method
./vendor/bin/phpunit --filter test_decode_valid_token

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage/
```

---

## Testing Your Own App Code

Use the same patterns from the package tests:

```php
use LaravelShopify\Tests\TestCase;

class MyAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_api_endpoint()
    {
        // Create test shop
        Shop::create([
            'shop_domain' => 'test.myshopify.com',
            'access_token' => 'shpat_test',
            'is_installed' => true,
        ]);

        // Create a valid JWT
        $jwt = $this->makeSessionToken('test.myshopify.com');

        // Make authenticated request (but note: token exchange to Shopify will fail in tests)
        // Instead, test your middleware/controllers by setting attributes directly
        $request = Request::create('/api/test', 'GET');
        $request->attributes->set('shopify_shop_domain', 'test.myshopify.com');
        $request->attributes->set('shopify_access_token', 'shpat_test');
    }
}
```
