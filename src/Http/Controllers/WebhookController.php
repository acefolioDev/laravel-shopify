<?php

namespace LaravelShopify\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Handle incoming Shopify webhooks.
     *
     * Validates the HMAC signature and dispatches the configured Job.
     */
    public function handle(Request $request): JsonResponse
    {
        $hmacHeader = $request->header('X-Shopify-Hmac-Sha256', '');
        $topic = $request->header('X-Shopify-Topic', '');
        $shopDomain = $request->header('X-Shopify-Shop-Domain', '');
        $apiVersion = $request->header('X-Shopify-API-Version', '');

        $body = $request->getContent();

        if (! $this->verifyHmac($body, $hmacHeader)) {
            Log::warning('Shopify webhook HMAC verification failed', [
                'topic' => $topic,
                'shop' => $shopDomain,
            ]);

            return response()->json(['error' => 'Invalid HMAC'], 401);
        }

        $data = json_decode($body, true) ?? [];

        // Normalize topic: Shopify sends "products/update", config uses "PRODUCTS_UPDATE"
        $normalizedTopic = strtoupper(str_replace('/', '_', $topic));

        $webhooks = config('shopify-app.webhooks', []);
        $jobClass = $webhooks[$normalizedTopic] ?? $webhooks[$topic] ?? null;

        if (! $jobClass) {
            Log::info('Shopify webhook received but no handler configured', [
                'topic' => $topic,
                'shop' => $shopDomain,
            ]);

            return response()->json(['message' => 'No handler configured'], 200);
        }

        if (! class_exists($jobClass)) {
            Log::error("Shopify webhook job class not found: {$jobClass}", [
                'topic' => $topic,
                'shop' => $shopDomain,
            ]);

            return response()->json(['error' => 'Handler class not found'], 500);
        }

        dispatch(new $jobClass($shopDomain, $data, $topic, $apiVersion));

        Log::info('Shopify webhook dispatched', [
            'topic' => $topic,
            'shop' => $shopDomain,
            'job' => $jobClass,
        ]);

        return response()->json(['message' => 'Webhook processed'], 200);
    }

    /**
     * Verify the HMAC signature of a webhook request.
     */
    protected function verifyHmac(string $body, string $hmacHeader): bool
    {
        $secret = config('shopify-app.api_secret');

        if (empty($secret) || empty($hmacHeader)) {
            return false;
        }

        $calculatedHmac = base64_encode(
            hash_hmac('sha256', $body, $secret, true)
        );

        return hash_equals($calculatedHmac, $hmacHeader);
    }
}
