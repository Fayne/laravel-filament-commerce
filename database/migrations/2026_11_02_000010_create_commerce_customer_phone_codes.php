<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_customer_phone_codes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('phone', 32);
            $table->string('code_hash', 255);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'phone', 'consumed_at', 'expires_at'], 'commerce_phone_codes_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_customer_phone_codes');
    }
};
