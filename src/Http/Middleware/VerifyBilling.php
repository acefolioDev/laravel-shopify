<?php

namespace LaravelShopify\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use LaravelShopify\Models\Plan;
use LaravelShopify\Services\BillingService;

class VerifyBilling
{
    protected BillingService $billing;

    public function __construct(BillingService $billing)
    {
        $this->billing = $billing;
    }

    /**
     * Verify that the shop has an active billing plan.
     *
     * If no active plan exists, redirect to the Shopify checkout page using
     * the App Bridge redirect header pattern (Link header).
     */
    public function handle(Request $request, Closure $next, ?string $planSlug = null): Response
    {
        if (! config('shopify-app.billing.required', false)) {
            return $next($request);
        }

        $shopDomain = $request->attributes->get('shopify_shop_domain');

        if (! $shopDomain) {
            return response()->json([
                'error' => 'Shop domain not found. Ensure VerifyShopify middleware runs first.',
            ], 403);
        }

        $activePlan = Plan::forShop($shopDomain)->active()->first();

        if ($activePlan) {
            if ($planSlug && $activePlan->plan_slug !== $planSlug) {
                return $this->redirectToBilling($request, $shopDomain, $planSlug);
            }

            $request->attributes->set('shopify_plan', $activePlan);

            return $next($request);
        }

        $defaultPlan = $planSlug ?? array_key_first(config('shopify-app.billing.plans', []));

        if (! $defaultPlan) {
            return response()->json([
                'error' => 'No billing plans configured.',
            ], 500);
        }

        return $this->redirectToBilling($request, $shopDomain, $defaultPlan);
    }

    /**
     * Create a charge and redirect to Shopify's checkout page.
     *
     * Uses the Link header with rel="app-bridge-redirect-endpoint" to break
     * out of the iframe for App Bridge compatibility.
     */
    protected function redirectToBilling(Request $request, string $shopDomain, string $planSlug): Response
    {
        $accessToken = $request->attributes->get('shopify_access_token');

        if (! $accessToken) {
            return response()->json([
                'error' => 'Access token not available for billing redirect.',
            ], 403);
        }

        try {
            $confirmationUrl = $this->billing->createCharge($shopDomain, $accessToken, $planSlug);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create billing charge: ' . $e->getMessage(),
            ], 500);
        }

        // App Bridge 4 redirect pattern — use Link header for iframe breakout
        return response()->json([
            'billing_required' => true,
            'confirmation_url' => $confirmationUrl,
            'plan' => $planSlug,
        ], 402)
            ->withHeaders([
                'Link' => '<' . $confirmationUrl . '>; rel="app-bridge-redirect-endpoint"',
                'X-Shopify-API-Request-Failure-Reauthorize' => '1',
                'X-Shopify-API-Request-Failure-Reauthorize-Url' => $confirmationUrl,
            ]);
    }
}
