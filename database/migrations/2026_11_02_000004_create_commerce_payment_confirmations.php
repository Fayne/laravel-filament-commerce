<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_payment_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->ulid('order_id');
            $table->string('provider', 64);
            $table->string('provider_reference', 128);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('outcome', 24);
            $table->timestamps();
            $table->unique(['tenant_id', 'provider', 'provider_reference'], 'ec_payment_confirms_tenant_provider_reference_unique');
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('commerce_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_payment_confirmations');
    }
};
