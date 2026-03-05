<?php

namespace LaravelShopify\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Session extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'is_online' => 'boolean',
        'user_email_verified' => 'boolean',
        'account_owner' => 'boolean',
        'expires_at' => 'datetime',
        'associated_user' => 'array',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function getTable(): string
    {
        return config('shopify-app.tables.sessions', 'shopify_sessions');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_domain', 'shop_domain');
    }

    public function isExpired(): bool
    {
        if (! $this->expires_at) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return $this->access_token && ! $this->isExpired();
    }

    public function scopeForShop($query, string $shopDomain)
    {
        return $query->where('shop_domain', $shopDomain);
    }

    public function scopeOffline($query)
    {
        return $query->where('is_online', false);
    }

    public function scopeOnline($query)
    {
        return $query->where('is_online', true);
    }

    public function scopeValid($query)
    {
        return $query->whereNotNull('access_token')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}
