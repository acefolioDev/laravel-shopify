<?php

namespace LaravelShopify\Services;

use Illuminate\Support\Facades\Log;
use LaravelShopify\Exceptions\ShopifyApiException;

/**
 * Registers configured webhooks with Shopify via the GraphQL Admin API.
 *
 * Called automatically during token exchange when a shop is installed,
 * or can be invoked manually via the service container.
 */
class WebhookRegistrar
{
    protected GraphQLClient $graphql;

    public function __construct(GraphQLClient $graphql)
    {
        $this->graphql = $graphql;
    }

    /**
     * Register all configured webhooks for a shop.
     *
     * @param string $shopDomain
     * @param string $accessToken
     * @return array Results for each webhook registration attempt
     */
    public function registerAll(string $shopDomain, string $accessToken): array
    {
        $webhooks = config('shopify-app.webhooks', []);
        $webhookPath = config('shopify-app.webhook_path', '/shopify/webhooks');
        $appUrl = rtrim(config('shopify-app.app_url'), '/');
        $callbackUrl = $appUrl . $webhookPath;

        $results = [];

        foreach ($webhooks as $topic => $jobClass) {
            try {
                $result = $this->register($shopDomain, $accessToken, $topic, $callbackUrl);
                $results[$topic] = ['success' => true, 'data' => $result];

                Log::info("Webhook registered: {$topic}", [
                    'shop' => $shopDomain,
                ]);
            } catch (ShopifyApiException $e) {
                $results[$topic] = ['success' => false, 'error' => $e->getMessage()];

                Log::warning("Failed to register webhook: {$topic}", [
                    'shop' => $shopDomain,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Register a single webhook subscription.
     */
    public function register(
        string $shopDomain,
        string $accessToken,
        string $topic,
        string $callbackUrl
    ): array {
        $mutation = <<<'GRAPHQL'
        mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
            webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
                webhookSubscription {
                    id
                    topic
                    endpoint {
                        ... on WebhookHttpEndpoint {
                            callbackUrl
                        }
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $variables = [
            'topic' => $topic,
            'webhookSubscription' => [
                'callbackUrl' => $callbackUrl,
                'format' => 'JSON',
            ],
        ];

        $result = $this->graphql->mutate($shopDomain, $accessToken, $mutation, $variables);

        $data = $result['webhookSubscriptionCreate'] ?? [];

        if (! empty($data['userErrors'])) {
            $errors = array_map(fn ($e) => $e['message'], $data['userErrors']);
            throw new ShopifyApiException(
                "Failed to register webhook {$topic}: " . implode('; ', $errors),
                $data['userErrors']
            );
        }

        return $data['webhookSubscription'] ?? [];
    }

    /**
     * List all registered webhooks for a shop.
     */
    public function listAll(string $shopDomain, string $accessToken): array
    {
        $query = <<<'GRAPHQL'
        {
            webhookSubscriptions(first: 50) {
                edges {
                    node {
                        id
                        topic
                        endpoint {
                            ... on WebhookHttpEndpoint {
                                callbackUrl
                            }
                        }
                        createdAt
                    }
                }
            }
        }
        GRAPHQL;

        $result = $this->graphql->query($shopDomain, $accessToken, $query);

        return array_map(
            fn ($edge) => $edge['node'],
            $result['webhookSubscriptions']['edges'] ?? []
        );
    }

    /**
     * Delete a webhook subscription by its GID.
     */
    public function delete(string $shopDomain, string $accessToken, string $webhookId): bool
    {
        $mutation = <<<'GRAPHQL'
        mutation webhookSubscriptionDelete($id: ID!) {
            webhookSubscriptionDelete(id: $id) {
                deletedWebhookSubscriptionId
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $result = $this->graphql->mutate($shopDomain, $accessToken, $mutation, ['id' => $webhookId]);

        $data = $result['webhookSubscriptionDelete'] ?? [];

        if (! empty($data['userErrors'])) {
            $errors = array_map(fn ($e) => $e['message'], $data['userErrors']);
            throw new ShopifyApiException(
                "Failed to delete webhook {$webhookId}: " . implode('; ', $errors),
                $data['userErrors']
            );
        }

        return ! empty($data['deletedWebhookSubscriptionId']);
    }
}
