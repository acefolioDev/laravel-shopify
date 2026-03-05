<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique()->index();
            $table->string('shop_domain')->index();
            $table->string('access_token')->nullable();
            $table->string('refresh_token')->nullable();
            $table->string('scope')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_online')->default(false);
            $table->string('online_access_info')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_first_name')->nullable();
            $table->string('user_last_name')->nullable();
            $table->string('user_email')->nullable();
            $table->boolean('user_email_verified')->nullable();
            $table->boolean('account_owner')->nullable();
            $table->string('locale')->nullable();
            $table->string('collaborator')->nullable();
            $table->json('associated_user')->nullable();
            $table->timestamps();

            $table->index(['shop_domain', 'is_online']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_sessions');
    }
};
