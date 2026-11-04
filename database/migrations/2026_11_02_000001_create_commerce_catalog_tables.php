<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_tenant_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->char('currency', 3)->default('CNY');
            $table->timestamps();
        });

        Schema::create('commerce_products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('type', 24);
            $table->string('status', 24)->default('draft');
            $table->string('delivery_handler')->nullable();
            $table->longText('delivery_config')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('commerce_product_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('product_id');
            $table->string('locale', 16);
            $table->string('name');
            $table->longText('description')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'product_id', 'locale']);
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('commerce_products')->cascadeOnDelete();
        });

        Schema::create('commerce_product_variants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('product_id');
            $table->string('sku');
            $table->unsignedBigInteger('price_amount');
            $table->char('currency', 3)->default('CNY');
            $table->json('option_values')->nullable();
            $table->json('extension_data')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('commerce_products')->cascadeOnDelete();
        });

        Schema::create('commerce_product_extensions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('product_id');
            $table->string('extension_key', 64);
            $table->json('data');
            $table->unique(['tenant_id', 'product_id', 'extension_key'], 'ec_product_extension_unique');
            $table->foreign(['tenant_id', 'product_id'], 'ec_product_extension_product_fk')
                ->references(['tenant_id', 'id'])->on('commerce_products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_product_extensions');
        Schema::dropIfExists('commerce_product_variants');
        Schema::dropIfExists('commerce_product_translations');
        Schema::dropIfExists('commerce_products');
        Schema::dropIfExists('commerce_tenant_settings');
    }
};
