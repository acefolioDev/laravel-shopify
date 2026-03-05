<?php

namespace LaravelShopify\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use LaravelShopify\Exceptions\ShopifyApiException;

class GraphQLClient
{
    protected string $apiVersion;
    protected Client $httpClient;
    protected RateLimiter $rateLimiter;
    protected int $maxRetries;
    protected float $retryAfterSeconds;

    public function __construct(string $apiVersion, array $rateLimitConfig)
    {
        $this->apiVersion = $apiVersion;
        $this->httpClient = new Client(['timeout' => 30]);
        $this->rateLimiter = new RateLimiter(
            $rateLimitConfig['max_cost'] ?? 1000,
            $rateLimitConfig['restore_rate'] ?? 50,
        );
        $this->maxRetries = config('shopify-app.rate_limit.max_retries', 3);
        $this->retryAfterSeconds = config('shopify-app.rate_limit.retry_after_seconds', 1);
    }

    /**
     * Execute a GraphQL query against the Shopify Admin API.
     *
     * @param string $shopDomain
     * @param string $accessToken
     * @param string $query The GraphQL query or mutation string
     * @param array $variables Optional variables for the query
     * @param int $cost Estimated query cost for rate limiting
     * @return array The decoded response data
     * @throws ShopifyApiException
     */
    public function query(
        string $shopDomain,
        string $accessToken,
        string $query,
        array $variables = [],
        int $cost = 10
    ): array {
        $this->rateLimiter->throttle($shopDomain, $cost);

        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/graphql.json";

        $payload = ['query' => $query];
        if (! empty($variables)) {
            $payload['variables'] = $variables;
        }

        $attempt = 0;

        while ($attempt <= $this->maxRetries) {
            try {
                $response = $this->httpClient->post($url, [
                    'json' => $payload,
                    'headers' => [
                        'X-Shopify-Access-Token' => $accessToken,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                ]);

                $body = json_decode($response->getBody()->getContents(), true);

                // Update rate limiter from throttle status if present
                if (isset($body['extensions']['cost']['throttleStatus']['currentlyAvailable'])) {
                    $this->rateLimiter->updateFromResponse(
                        $shopDomain,
                        (int) $body['extensions']['cost']['throttleStatus']['currentlyAvailable']
                    );
                }

                // Check for GraphQL-level errors
                if (isset($body['errors']) && ! empty($body['errors'])) {
                    $errorMessages = array_map(
                        fn ($e) => $e['message'] ?? 'Unknown error',
                        $body['errors']
                    );

                    // Check for throttled errors
                    $isThrottled = collect($body['errors'])->contains(
                        fn ($e) => str_contains($e['message'] ?? '', 'Throttled')
                    );

                    if ($isThrottled && $attempt < $this->maxRetries) {
                        $attempt++;
                        $this->rateLimiter->handleRetryAfter($shopDomain, $this->retryAfterSeconds);
                        continue;
                    }

                    throw new ShopifyApiException(
                        'GraphQL errors: ' . implode('; ', $errorMessages),
                        $body['errors']
                    );
                }

                return $body['data'] ?? $body;
            } catch (ClientException $e) {
                $statusCode = $e->getResponse()->getStatusCode();

                if ($statusCode === 429 && $attempt < $this->maxRetries) {
                    $retryAfter = (float) ($e->getResponse()->getHeaderLine('Retry-After') ?: $this->retryAfterSeconds);
                    $this->rateLimiter->handleRetryAfter($shopDomain, $retryAfter);
                    $attempt++;
                    continue;
                }

                throw new ShopifyApiException(
                    "GraphQL request failed ({$statusCode}): " . $e->getMessage(),
                    [],
                    $statusCode,
                    $e
                );
            } catch (GuzzleException $e) {
                throw new ShopifyApiException(
                    'GraphQL request failed: ' . $e->getMessage(),
                    [],
                    $e->getCode(),
                    $e
                );
            }

            $attempt++;
        }

        throw new ShopifyApiException('Max retries exceeded for GraphQL request to ' . $shopDomain);
    }

    /**
     * Alias for query — both queries and mutations use the same endpoint.
     */
    public function mutate(
        string $shopDomain,
        string $accessToken,
        string $query,
        array $variables = [],
        int $cost = 10
    ): array {
        return $this->query($shopDomain, $accessToken, $query, $variables, $cost);
    }
}
