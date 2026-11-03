<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('status', 24)->default('awaiting_payment');
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_amount');
            $table->unsignedBigInteger('shipping_amount');
            $table->unsignedBigInteger('discount_amount');
            $table->unsignedBigInteger('payable_amount');
            $table->string('coupon_code')->nullable();
            $table->uuid('coupon_reservation_id')->nullable();
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->json('buyer_snapshot');
            $table->json('shipping_snapshot')->nullable();
            $table->json('promotion_snapshot');
            $table->string('locale', 16);
            $table->timestamp('reservation_expires_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('commerce_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->ulid('order_id');
            $table->unsignedBigInteger('variant_id');
            $table->string('sku');
            $table->string('product_type', 24);
            $table->string('product_name');
            $table->json('option_snapshot')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_amount');
            $table->unsignedBigInteger('line_amount');
            $table->unsignedBigInteger('discount_amount');
            $table->unsignedBigInteger('payable_amount');
            $table->json('discount_units');
            $table->json('delivery_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('commerce_orders')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'variant_id'])->references(['tenant_id', 'id'])->on('commerce_product_variants')->restrictOnDelete();
        });

        Schema::create('commerce_outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('event_key')->unique();
            $table->string('event_type', 100);
            $table->json('payload');
            $table->timestamp('available_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('lock_token', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_outbox_messages');
        Schema::dropIfExists('commerce_order_lines');
        Schema::dropIfExists('commerce_orders');
    }
};
