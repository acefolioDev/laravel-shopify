<?php

namespace LaravelShopify\Facades;

use Illuminate\Support\Facades\Facade;
use LaravelShopify\Services\GraphQLClient;

/**
 * @method static array query(string $shop, string $accessToken, string $query, array $variables = [])
 * @method static array mutate(string $shop, string $accessToken, string $query, array $variables = [])
 *
 * @see \LaravelShopify\Services\GraphQLClient
 */
class ShopifyApp extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GraphQLClient::class;
    }
}
