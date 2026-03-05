<?php

namespace LaravelShopify\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelShopify\Models\Session;
use LaravelShopify\Services\BillingService;

class BillingController extends Controller
{
    protected BillingService $billing;

    public function __construct(BillingService $billing)
    {
        $this->billing = $billing;
    }

    /**
     * Handle the billing callback after a merchant approves/declines a charge.
     *
     * Shopify redirects back here with the charge_id query parameter.
     * The charge status is verified with Shopify's API before activation.
     */
    public function callback(Request $request): JsonResponse
    {
        $shopDomain = $request->query('shop');
        $planSlug = $request->query('plan');
        $chargeId = $request->query('charge_id');

        if (! $shopDomain || ! $planSlug || ! $chargeId) {
            return response()->json([
                'error' => 'Missing required parameters: shop, plan, charge_id.',
            ], 400);
        }

        // Retrieve the shop's offline session to get the access token
        $session = Session::forShop($shopDomain)->offline()->valid()->first();

        if (! $session) {
            return response()->json([
                'error' => 'No valid session found for this shop. Please reinstall the app.',
            ], 401);
        }

        try {
            $plan = $this->billing->confirmCharge($shopDomain, $session->access_token, $planSlug, $chargeId);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to confirm charge: ' . $e->getMessage(),
            ], 500);
        }

        // Redirect back into the app within the Shopify Admin
        $redirectUrl = "https://{$shopDomain}/admin/apps/" . config('shopify-app.api_key');

        return response()->json([
            'success' => true,
            'plan' => $plan->plan_name,
            'status' => $plan->status,
            'redirect_url' => $redirectUrl,
        ])->withHeaders([
            'Link' => '<' . $redirectUrl . '>; rel="app-bridge-redirect-endpoint"',
        ]);
    }
}
