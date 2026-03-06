# 11 - Billing System

The package provides a complete billing system for charging Shopify merchants. It supports recurring subscriptions, one-time charges, usage-based pricing, and free trials.

**Source files:**
- `src/Services/BillingService.php` — Core billing logic
- `src/Http/Middleware/VerifyBilling.php` — Automatic billing enforcement
- `src/Http/Controllers/BillingController.php` — Callback handler
- `src/Models/Plan.php` — Plan storage

---

## How Shopify Billing Works (The Basics)

1. Your app creates a **charge** via Shopify's GraphQL API
2. Shopify returns a **confirmation URL**
3. The merchant is redirected to that URL (Shopify's checkout page)
4. The merchant approves or declines
5. Shopify redirects back to your app's **callback URL**
6. Your app confirms the charge and activates the plan

All charges go through Shopify's billing system — you never handle credit cards directly.

---

## Configuration

In `config/shopify-app.php`:

```php
'billing' => [
    'enabled' => env('SHOPIFY_BILLING_ENABLED', false),
    'required' => env('SHOPIFY_BILLING_REQUIRED', false),

    'plans' => [
        'basic' => [
            'name' => 'Basic Plan',
            'type' => 'recurring',
            'price' => 9.99,
            'currency' => 'USD',
            'interval' => 'EVERY_30_DAYS',
            'trial_days' => 7,
            'test' => env('SHOPIFY_BILLING_TEST', true),
        ],
        'pro' => [
            'name' => 'Pro Plan',
            'type' => 'recurring',
            'price' => 29.99,
            'currency' => 'USD',
            'interval' => 'EVERY_30_DAYS',
            'trial_days' => 14,
            'test' => true,
            'capped_amount' => 100.00,
            'terms' => 'Usage charges for API calls',
        ],
        'lifetime' => [
            'name' => 'Lifetime Access',
            'type' => 'one_time',
            'price' => 199.99,
            'currency' => 'USD',
            'test' => true,
        ],
    ],
],
```

### Config Fields Explained

- **`enabled`** — Master switch. If `false`, billing features do nothing.
- **`required`** — If `true`, the `verify.billing` middleware blocks requests from shops without an active plan.
- **`plans`** — Each key (e.g., `basic`) becomes the `plan_slug` used throughout the system.

### Plan Types

| Type | GraphQL Mutation | Description |
|---|---|---|
| `recurring` | `appSubscriptionCreate` | Monthly/annual subscription |
| `one_time` | `appPurchaseOneTimeCreate` | Single payment |

### Usage-Based Billing

If a plan has `capped_amount` set, the `BillingService` adds `appUsagePricingDetails` to the GraphQL mutation. This allows you to charge merchants based on usage up to the capped amount.

---

## `BillingService` — Core Logic

**File:** `src/Services/BillingService.php`

### `createCharge(string $shopDomain, string $accessToken, string $planSlug): string`

Creates a charge and returns the confirmation URL.

**Flow:**
1. Look up plan config by slug → throws `ShopifyApiException` if not found
2. Build the return URL: `{app_url}/shopify/billing/callback?shop={shop}&plan={slug}`
3. Call `createRecurringCharge()` or `createOneTimeCharge()` based on type
4. Store a `Plan` record with `status = 'pending'`
5. Return the confirmation URL

### `createRecurringCharge()` — GraphQL Mutation

```graphql
mutation appSubscriptionCreate($name: String!, $returnUrl: URL!, $trialDays: Int, $test: Boolean, $lineItems: [AppSubscriptionLineItemInput!]!) {
    appSubscriptionCreate(
        name: $name
        returnUrl: $returnUrl
        trialDays: $trialDays
        test: $test
        lineItems: $lineItems
    ) {
        appSubscription { id }
        confirmationUrl
        userErrors { field message }
    }
}
```

The `lineItems` array includes `appRecurringPricingDetails` and optionally `appUsagePricingDetails` if `capped_amount` is set.

### `createOneTimeCharge()` — GraphQL Mutation

```graphql
mutation appPurchaseOneTimeCreate($name: String!, $price: MoneyInput!, $returnUrl: URL!, $test: Boolean) {
    appPurchaseOneTimeCreate(
        name: $name
        price: $price
        returnUrl: $returnUrl
        test: $test
    ) {
        appPurchaseOneTime { id }
        confirmationUrl
        userErrors { field message }
    }
}
```

### `checkActiveSubscription(string $shopDomain, string $accessToken): ?array`

Queries Shopify to check if the shop has an active subscription:

```graphql
{
    currentAppInstallation {
        activeSubscriptions {
            id name status currentPeriodEnd trialDays test
            lineItems {
                plan {
                    pricingDetails {
                        ... on AppRecurringPricing { price { amount currencyCode } interval }
                        ... on AppUsagePricing { cappedAmount { amount currencyCode } terms }
                    }
                }
            }
        }
    }
}
```

Returns the first active subscription or `null`.

### `confirmCharge(string $shopDomain, string $planSlug, string $chargeId): Plan`

Called by the `BillingController` after merchant approves:

1. Find the pending plan in DB
2. Cancel all other active plans for this shop (sets `status = 'cancelled'`)
3. Update the plan: `status = 'active'`, set `activated_at`, calculate `trial_ends_at`
4. Return the fresh plan

---

## The Billing Flow (End-to-End)

```
1. Merchant makes an API request
        │
        ▼
2. VerifyBilling middleware checks for active plan
   → No active plan found
        │
        ▼
3. Middleware calls BillingService::createCharge()
   → Sends GraphQL mutation to Shopify
   → Gets confirmation URL
   → Stores pending Plan in DB
        │
        ▼
4. Middleware returns 402 with Link header
   → Frontend detects the Link header
   → App Bridge redirects to Shopify checkout
        │
        ▼
5. Merchant approves on Shopify's page
   → Shopify redirects to /shopify/billing/callback?shop=...&plan=...&charge_id=...
        │
        ▼
6. BillingController::callback()
   → Calls BillingService::confirmCharge()
   → Plan status: pending → active
   → Redirect to embedded app URL
        │
        ▼
7. Next API request passes VerifyBilling ✅
```

---

## Programmatic Usage

You can use the `BillingService` directly in your controllers:

```php
use LaravelShopify\Services\BillingService;

class BillingPageController extends Controller
{
    use ShopifyRequestContext;

    public function createSubscription(Request $request, BillingService $billing)
    {
        $shop = $this->getShopDomain($request);
        $token = $this->getAccessToken($request);

        $confirmationUrl = $billing->createCharge($shop, $token, 'pro');

        return $this->appBridgeRedirect($confirmationUrl);
    }

    public function checkStatus(Request $request, BillingService $billing)
    {
        $shop = $this->getShopDomain($request);
        $token = $this->getAccessToken($request);

        $subscription = $billing->checkActiveSubscription($shop, $token);

        return response()->json(['subscription' => $subscription]);
    }
}
```

---

## Testing Billing

Set `test: true` in your plan config (or `SHOPIFY_BILLING_TEST=true` in `.env`). Test charges:
- Don't actually charge the merchant
- Behave identically to real charges
- Can be approved/declined in the Shopify Admin

**Always use test mode during development.**
