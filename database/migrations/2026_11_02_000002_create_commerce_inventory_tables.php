<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_stock_levels', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('variant_id');
            $table->unsignedBigInteger('on_hand')->default(0);
            $table->unsignedBigInteger('reserved')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'variant_id']);
            $table->foreign(['tenant_id', 'variant_id'])->references(['tenant_id', 'id'])->on('commerce_product_variants')->cascadeOnDelete();
        });

        Schema::create('commerce_stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('variant_id');
            $table->string('source_type', 64);
            $table->string('source_id', 128);
            $table->unsignedInteger('quantity');
            $table->string('status', 16)->default('reserved');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'source_type', 'source_id', 'variant_id']);
            $table->foreign(['tenant_id', 'variant_id'])->references(['tenant_id', 'variant_id'])->on('commerce_stock_levels')->cascadeOnDelete();
        });

        Schema::create('commerce_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('variant_id');
            $table->string('source_type', 64)->nullable();
            $table->string('source_id', 128)->nullable();
            $table->string('reason', 32);
            $table->bigInteger('on_hand_delta');
            $table->bigInteger('reserved_delta');
            $table->timestamps();
            $table->index(['tenant_id', 'variant_id', 'created_at']);
            $table->foreign(['tenant_id', 'variant_id'])->references(['tenant_id', 'variant_id'])->on('commerce_stock_levels')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_stock_movements');
        Schema::dropIfExists('commerce_stock_reservations');
        Schema::dropIfExists('commerce_stock_levels');
    }
};
