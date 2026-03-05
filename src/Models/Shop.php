<?php

namespace LaravelShopify\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shop extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'is_installed' => 'boolean',
        'is_freemium' => 'boolean',
        'token_expires_at' => 'datetime',
        'installed_at' => 'datetime',
        'uninstalled_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function getTable(): string
    {
        return config('shopify-app.tables.shops', 'shopify_shops');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class, 'shop_domain', 'shop_domain');
    }

    public function offlineSession(): HasOne
    {
        return $this->hasOne(Session::class, 'shop_domain', 'shop_domain')
            ->where('is_online', false)
            ->latest();
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class, 'shop_domain', 'shop_domain');
    }

    public function activePlan(): HasOne
    {
        return $this->hasOne(Plan::class, 'shop_domain', 'shop_domain')
            ->where('status', 'active')
            ->latest();
    }

    public function isTokenExpired(): bool
    {
        $session = $this->offlineSession;

        if (! $session || ! $session->expires_at) {
            return false;
        }

        $buffer = config('shopify-app.offline_tokens.refresh_buffer_seconds', 300);

        return $session->expires_at->subSeconds($buffer)->isPast();
    }

    public function needsReauth(): bool
    {
        $session = $this->offlineSession;

        if (! $session || ! $session->access_token) {
            return true;
        }

        if (config('shopify-app.offline_tokens.expiring', true) && $this->isTokenExpired()) {
            return true;
        }

        $requiredScopes = array_map('trim', explode(',', config('shopify-app.scopes', '')));
        $grantedScopes = array_map('trim', explode(',', $this->scopes ?? ''));

        return ! empty(array_diff($requiredScopes, $grantedScopes));
    }

    public function scopeInstalled($query)
    {
        return $query->where('is_installed', true);
    }

    public function scopeDomain($query, string $domain)
    {
        return $query->where('shop_domain', $domain);
    }
}
