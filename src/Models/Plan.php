<?php

namespace LaravelShopify\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Plan extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'price' => 'decimal:2',
        'capped_amount' => 'decimal:2',
        'trial_days' => 'integer',
        'test' => 'boolean',
        'activated_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'trial_ends_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('shopify-app.tables.plans', 'shopify_plans');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_domain', 'shop_domain');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isRecurring(): bool
    {
        return $this->type === 'recurring';
    }

    public function isOneTime(): bool
    {
        return $this->type === 'one_time';
    }

    public function isInTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForShop($query, string $shopDomain)
    {
        return $query->where('shop_domain', $shopDomain);
    }
}
