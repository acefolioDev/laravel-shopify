<?php

namespace LaravelShopify\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelShopify\Models\Shop;

class ShopUninstalled
{
    use Dispatchable, SerializesModels;

    public Shop $shop;

    public function __construct(Shop $shop)
    {
        $this->shop = $shop;
    }
}
