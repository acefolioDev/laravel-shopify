<?php

namespace LaravelShopify\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelShopify\Services\BillingService;
use LaravelShopify\Support\ShopifyHelper;

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
     * After confirming the charge, we redirect back into the embedded app.
     */
    public function callback(Request $request): RedirectResponse
    {
        $shopDomain = $request->query('shop');
        $planSlug = $request->query('plan');
        $chargeId = $request->query('charge_id');

        if (! $shopDomain || ! $planSlug || ! $chargeId) {
            logger()->warning('Billing callback received with missing parameters.', $request->query());
            return redirect()->to(ShopifyHelper::embeddedAppUrl($shopDomain ?? ''))
                ->with('billing_error', 'Invalid billing callback — missing required parameters.');
        }

        try {
            $this->billing->confirmCharge($shopDomain, $planSlug, $chargeId);
        } catch (\Exception $e) {
            logger()->error('Billing confirmation failed: ' . $e->getMessage());
            return redirect()->to(ShopifyHelper::embeddedAppUrl($shopDomain))
                ->with('billing_error', $e->getMessage());
        }

        // Redirect back into the embedded app inside Shopify Admin
        $embeddedUrl = ShopifyHelper::embeddedAppUrl($shopDomain);

        return redirect()->to($embeddedUrl);
    }
}
