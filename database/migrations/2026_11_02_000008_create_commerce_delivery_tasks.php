<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_delivery_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->ulid('order_id');
            $table->unsignedBigInteger('order_line_id');
            $table->unsignedInteger('unit_number');
            $table->string('handler_key', 100);
            $table->longText('config_snapshot');
            $table->string('status', 24)->default('pending');
            $table->string('idempotency_key', 191);
            $table->string('external_reference', 191)->nullable();
            $table->longText('result')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->string('revocation_status', 24)->nullable();
            $table->unsignedInteger('revocation_attempt')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'order_line_id', 'unit_number'], 'ec_delivery_tasks_tenant_order_line_unit_number_unique');
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('commerce_orders')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_line_id'])->references(['tenant_id', 'id'])->on('commerce_order_lines')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_delivery_tasks');
    }
};
