<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table): void {
            $table->string('payment_status', 20)->default('pending')->after('status');
            $table->string('payment_provider', 64)->nullable()->after('payment_status');
            $table->string('payment_reference', 128)->nullable()->after('payment_provider');
            $table->timestamp('paid_at')->nullable()->after('payment_reference');
        });

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
            $table->unique(['tenant_id', 'provider', 'provider_reference']);
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('commerce_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_payment_confirmations');
        Schema::table('commerce_orders', function (Blueprint $table): void {
            $table->dropColumn(['payment_status', 'payment_provider', 'payment_reference', 'paid_at']);
        });
    }
};
