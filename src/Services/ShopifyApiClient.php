<?php

namespace LaravelShopify\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use LaravelShopify\Exceptions\ShopifyApiException;

class ShopifyApiClient
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
            $rateLimitConfig['bucket_size'] ?? 40,
            $rateLimitConfig['leak_rate'] ?? 2,
        );
        $this->maxRetries = config('shopify-app.rate_limit.max_retries', 3);
        $this->retryAfterSeconds = config('shopify-app.rate_limit.retry_after_seconds', 1);
    }

    /**
     * Make a REST API request to Shopify.
     */
    public function request(
        string $method,
        string $shopDomain,
        string $accessToken,
        string $endpoint,
        array $data = [],
        array $query = []
    ): array {
        $this->rateLimiter->throttle($shopDomain);

        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/{$endpoint}";

        $options = [
            'headers' => [
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];

        if (! empty($query)) {
            $options['query'] = $query;
        }

        if (! empty($data) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            $options['json'] = $data;
        }

        $attempt = 0;

        while ($attempt <= $this->maxRetries) {
            try {
                $response = $this->httpClient->request($method, $url, $options);

                $body = json_decode($response->getBody()->getContents(), true);

                // Update rate limiter from response headers
                $callLimit = $response->getHeaderLine('X-Shopify-Shop-Api-Call-Limit');
                if ($callLimit && str_contains($callLimit, '/')) {
                    [$used, $total] = explode('/', $callLimit);
                    $available = (int) $total - (int) $used;
                    $this->rateLimiter->updateFromResponse($shopDomain, $available);
                }

                return $body ?? [];
            } catch (ClientException $e) {
                $statusCode = $e->getResponse()->getStatusCode();

                if ($statusCode === 429 && $attempt < $this->maxRetries) {
                    $retryAfter = (float) ($e->getResponse()->getHeaderLine('Retry-After') ?: $this->retryAfterSeconds);
                    $this->rateLimiter->handleRetryAfter($shopDomain, $retryAfter);
                    $attempt++;
                    continue;
                }

                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);

                throw new ShopifyApiException(
                    "REST API request failed ({$statusCode}): " . ($responseBody['errors'] ?? $e->getMessage()),
                    $responseBody['errors'] ?? [],
                    $statusCode,
                    $e
                );
            } catch (GuzzleException $e) {
                throw new ShopifyApiException(
                    'REST API request failed: ' . $e->getMessage(),
                    [],
                    $e->getCode(),
                    $e
                );
            }

            $attempt++;
        }

        throw new ShopifyApiException('Max retries exceeded for REST request to ' . $shopDomain);
    }

    public function get(string $shopDomain, string $accessToken, string $endpoint, array $query = []): array
    {
        return $this->request('GET', $shopDomain, $accessToken, $endpoint, [], $query);
    }

    public function post(string $shopDomain, string $accessToken, string $endpoint, array $data = []): array
    {
        return $this->request('POST', $shopDomain, $accessToken, $endpoint, $data);
    }

    public function put(string $shopDomain, string $accessToken, string $endpoint, array $data = []): array
    {
        return $this->request('PUT', $shopDomain, $accessToken, $endpoint, $data);
    }

    public function delete(string $shopDomain, string $accessToken, string $endpoint): array
    {
        return $this->request('DELETE', $shopDomain, $accessToken, $endpoint);
    }
}
