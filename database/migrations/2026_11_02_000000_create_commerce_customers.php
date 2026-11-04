<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('wechat_appid', 64)->nullable();
            $table->string('wechat_openid', 128)->nullable();
            $table->unique(['tenant_id', 'wechat_appid', 'wechat_openid'], 'commerce_customers_tenant_wechat_identity_unique');
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'phone']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_customers');
    }
};
