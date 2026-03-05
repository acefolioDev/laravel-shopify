<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_plans', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain')->index();
            $table->string('plan_slug');
            $table->string('plan_name');
            $table->string('type'); // 'recurring' or 'one_time'
            $table->decimal('price', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('interval')->nullable(); // EVERY_30_DAYS, ANNUAL
            $table->integer('trial_days')->default(0);
            $table->decimal('capped_amount', 10, 2)->nullable();
            $table->string('terms')->nullable();
            $table->boolean('test')->default(false);
            $table->string('charge_id')->nullable()->index();
            $table->string('status')->default('pending'); // pending, active, declined, expired, cancelled
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();

            $table->index(['shop_domain', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_plans');
    }
};
