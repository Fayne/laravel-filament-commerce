<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_refund_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->ulid('order_id');
            $table->string('status', 24)->default('pending_review');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount');
            $table->string('reason', 500);
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('commerce_orders')->restrictOnDelete();
        });

        Schema::create('commerce_refund_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->ulid('refund_request_id');
            $table->unsignedBigInteger('order_line_id');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('amount');
            $table->json('unit_allocations');
            $table->timestamps();
            $table->unique(['tenant_id', 'refund_request_id', 'order_line_id']);
            $table->foreign(['tenant_id', 'refund_request_id'])->references(['tenant_id', 'id'])->on('commerce_refund_requests')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_line_id'])->references(['tenant_id', 'id'])->on('commerce_order_lines')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_refund_request_lines');
        Schema::dropIfExists('commerce_refund_requests');
    }
};
