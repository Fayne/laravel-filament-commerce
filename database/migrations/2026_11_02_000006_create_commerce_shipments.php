<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_shipments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->ulid('order_id');
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('status', 20)->default('shipped');
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->timestamp('shipped_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('commerce_orders')->restrictOnDelete();
        });

        Schema::create('commerce_shipment_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->ulid('shipment_id');
            $table->unsignedBigInteger('order_line_id');
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->foreign(['tenant_id', 'shipment_id'])->references(['tenant_id', 'id'])->on('commerce_shipments')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_line_id'])->references(['tenant_id', 'id'])->on('commerce_order_lines')->restrictOnDelete();
            $table->unique(['tenant_id', 'shipment_id', 'order_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_shipment_lines');
        Schema::dropIfExists('commerce_shipments');
    }
};
