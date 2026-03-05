<?php

namespace LaravelShopify\Services;

use LaravelShopify\Exceptions\ShopifyApiException;
use LaravelShopify\Models\Plan;

class BillingService
{
    protected GraphQLClient $graphql;
    protected array $billingConfig;

    public function __construct(GraphQLClient $graphql, array $billingConfig)
    {
        $this->graphql = $graphql;
        $this->billingConfig = $billingConfig;
    }

    /**
     * Create a charge (recurring or one-time) and return the confirmation URL.
     */
    public function createCharge(string $shopDomain, string $accessToken, string $planSlug): string
    {
        $planConfig = $this->billingConfig['plans'][$planSlug] ?? null;

        if (! $planConfig) {
            throw new ShopifyApiException("Billing plan '{$planSlug}' not found in configuration.");
        }

        $returnUrl = rtrim(config('shopify-app.app_url'), '/') . '/shopify/billing/callback?' . http_build_query([
            'shop' => $shopDomain,
            'plan' => $planSlug,
        ]);

        if ($planConfig['type'] === 'recurring') {
            return $this->createRecurringCharge($shopDomain, $accessToken, $planSlug, $planConfig, $returnUrl);
        }

        return $this->createOneTimeCharge($shopDomain, $accessToken, $planSlug, $planConfig, $returnUrl);
    }

    /**
     * Create a recurring subscription charge via GraphQL.
     */
    protected function createRecurringCharge(
        string $shopDomain,
        string $accessToken,
        string $planSlug,
        array $planConfig,
        string $returnUrl
    ): string {
        $lineItems = [
            [
                'plan' => [
                    'appRecurringPricingDetails' => [
                        'price' => [
                            'amount' => $planConfig['price'],
                            'currencyCode' => $planConfig['currency'] ?? 'USD',
                        ],
                        'interval' => $planConfig['interval'] ?? 'EVERY_30_DAYS',
                    ],
                ],
            ],
        ];

        // Add usage-based pricing if capped_amount is set
        if (! empty($planConfig['capped_amount'])) {
            $lineItems[0]['plan']['appUsagePricingDetails'] = [
                'cappedAmount' => [
                    'amount' => $planConfig['capped_amount'],
                    'currencyCode' => $planConfig['currency'] ?? 'USD',
                ],
                'terms' => $planConfig['terms'] ?? 'Usage charges',
            ];
        }

        $mutation = <<<'GRAPHQL'
        mutation appSubscriptionCreate($name: String!, $returnUrl: URL!, $trialDays: Int, $test: Boolean, $lineItems: [AppSubscriptionLineItemInput!]!) {
            appSubscriptionCreate(
                name: $name
                returnUrl: $returnUrl
                trialDays: $trialDays
                test: $test
                lineItems: $lineItems
            ) {
                appSubscription {
                    id
                }
                confirmationUrl
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $variables = [
            'name' => $planConfig['name'],
            'returnUrl' => $returnUrl,
            'trialDays' => $planConfig['trial_days'] ?? 0,
            'test' => $planConfig['test'] ?? false,
            'lineItems' => $lineItems,
        ];

        $result = $this->graphql->mutate($shopDomain, $accessToken, $mutation, $variables);

        $data = $result['appSubscriptionCreate'] ?? [];

        if (! empty($data['userErrors'])) {
            $errors = array_map(fn ($e) => $e['message'], $data['userErrors']);
            throw new ShopifyApiException(
                'Failed to create subscription: ' . implode('; ', $errors),
                $data['userErrors']
            );
        }

        if (empty($data['confirmationUrl'])) {
            throw new ShopifyApiException('No confirmation URL returned from subscription creation.');
        }

        // Persist the pending plan
        Plan::updateOrCreate(
            [
                'shop_domain' => $shopDomain,
                'plan_slug' => $planSlug,
                'status' => 'pending',
            ],
            [
                'plan_name' => $planConfig['name'],
                'type' => 'recurring',
                'price' => $planConfig['price'],
                'currency' => $planConfig['currency'] ?? 'USD',
                'interval' => $planConfig['interval'] ?? 'EVERY_30_DAYS',
                'trial_days' => $planConfig['trial_days'] ?? 0,
                'capped_amount' => $planConfig['capped_amount'] ?? null,
                'terms' => $planConfig['terms'] ?? null,
                'test' => $planConfig['test'] ?? false,
                'charge_id' => $data['appSubscription']['id'] ?? null,
            ]
        );

        return $data['confirmationUrl'];
    }

    /**
     * Create a one-time application charge via GraphQL.
     */
    protected function createOneTimeCharge(
        string $shopDomain,
        string $accessToken,
        string $planSlug,
        array $planConfig,
        string $returnUrl
    ): string {
        $mutation = <<<'GRAPHQL'
        mutation appPurchaseOneTimeCreate($name: String!, $price: MoneyInput!, $returnUrl: URL!, $test: Boolean) {
            appPurchaseOneTimeCreate(
                name: $name
                price: $price
                returnUrl: $returnUrl
                test: $test
            ) {
                appPurchaseOneTime {
                    id
                }
                confirmationUrl
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $variables = [
            'name' => $planConfig['name'],
            'price' => [
                'amount' => $planConfig['price'],
                'currencyCode' => $planConfig['currency'] ?? 'USD',
            ],
            'returnUrl' => $returnUrl,
            'test' => $planConfig['test'] ?? false,
        ];

        $result = $this->graphql->mutate($shopDomain, $accessToken, $mutation, $variables);

        $data = $result['appPurchaseOneTimeCreate'] ?? [];

        if (! empty($data['userErrors'])) {
            $errors = array_map(fn ($e) => $e['message'], $data['userErrors']);
            throw new ShopifyApiException(
                'Failed to create one-time charge: ' . implode('; ', $errors),
                $data['userErrors']
            );
        }

        if (empty($data['confirmationUrl'])) {
            throw new ShopifyApiException('No confirmation URL returned from one-time charge creation.');
        }

        Plan::updateOrCreate(
            [
                'shop_domain' => $shopDomain,
                'plan_slug' => $planSlug,
                'status' => 'pending',
            ],
            [
                'plan_name' => $planConfig['name'],
                'type' => 'one_time',
                'price' => $planConfig['price'],
                'currency' => $planConfig['currency'] ?? 'USD',
                'test' => $planConfig['test'] ?? false,
                'charge_id' => $data['appPurchaseOneTime']['id'] ?? null,
            ]
        );

        return $data['confirmationUrl'];
    }

    /**
     * Check if a shop has an active subscription by querying Shopify.
     */
    public function checkActiveSubscription(string $shopDomain, string $accessToken): ?array
    {
        $query = <<<'GRAPHQL'
        {
            currentAppInstallation {
                activeSubscriptions {
                    id
                    name
                    status
                    currentPeriodEnd
                    trialDays
                    test
                    lineItems {
                        plan {
                            pricingDetails {
                                ... on AppRecurringPricing {
                                    price {
                                        amount
                                        currencyCode
                                    }
                                    interval
                                }
                                ... on AppUsagePricing {
                                    cappedAmount {
                                        amount
                                        currencyCode
                                    }
                                    terms
                                }
                            }
                        }
                    }
                }
            }
        }
        GRAPHQL;

        $result = $this->graphql->query($shopDomain, $accessToken, $query);

        $subscriptions = $result['currentAppInstallation']['activeSubscriptions'] ?? [];

        return ! empty($subscriptions) ? $subscriptions[0] : null;
    }

    /**
     * Confirm a billing callback — verify the charge with Shopify, then activate locally.
     *
     * @throws ShopifyApiException If the charge cannot be verified or is not active.
     */
    public function confirmCharge(string $shopDomain, string $accessToken, string $planSlug, string $chargeId): Plan
    {
        $plan = Plan::where('shop_domain', $shopDomain)
            ->where('plan_slug', $planSlug)
            ->where('status', 'pending')
            ->latest()
            ->firstOrFail();

        // Verify the charge status with Shopify before activating locally
        $verifiedStatus = $this->verifyChargeWithShopify($shopDomain, $accessToken, $chargeId);

        if (! in_array($verifiedStatus, ['ACTIVE', 'ACCEPTED'], true)) {
            throw new ShopifyApiException(
                "Charge {$chargeId} is not active on Shopify. Status: {$verifiedStatus}"
            );
        }

        // Cancel any other active plans for this shop
        Plan::where('shop_domain', $shopDomain)
            ->where('status', 'active')
            ->where('id', '!=', $plan->id)
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

        $plan->update([
            'status' => 'active',
            'charge_id' => $chargeId,
            'activated_at' => now(),
            'trial_ends_at' => $plan->trial_days > 0
                ? now()->addDays($plan->trial_days)
                : null,
        ]);

        return $plan->fresh();
    }

    /**
     * Verify a charge's status by querying the Shopify GraphQL Admin API.
     *
     * @throws ShopifyApiException If the charge cannot be found or the query fails.
     */
    public function verifyChargeWithShopify(string $shopDomain, string $accessToken, string $chargeId): string
    {
        $query = <<<'GRAPHQL'
        query($id: ID!) {
            node(id: $id) {
                ... on AppSubscription {
                    id
                    status
                    name
                }
                ... on AppPurchaseOneTime {
                    id
                    status
                    name
                }
            }
        }
        GRAPHQL;

        $result = $this->graphql->query($shopDomain, $accessToken, $query, ['id' => $chargeId]);

        $node = $result['node'] ?? null;

        if (! $node || ! isset($node['status'])) {
            throw new ShopifyApiException(
                "Unable to verify charge {$chargeId} with Shopify. Charge not found or invalid."
            );
        }

        return $node['status'];
    }
}
