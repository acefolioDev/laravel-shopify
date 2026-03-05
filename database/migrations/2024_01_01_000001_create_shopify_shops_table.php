<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_shops', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain')->unique()->index();
            $table->string('shop_name')->nullable();
            $table->string('email')->nullable();
            $table->string('access_token')->nullable();
            $table->string('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('scopes')->nullable();
            $table->string('plan_name')->nullable();
            $table->string('shop_owner')->nullable();
            $table->string('country')->nullable();
            $table->string('currency')->nullable();
            $table->string('timezone')->nullable();
            $table->boolean('is_installed')->default(false);
            $table->boolean('is_freemium')->default(false);
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_shops');
    }
};
