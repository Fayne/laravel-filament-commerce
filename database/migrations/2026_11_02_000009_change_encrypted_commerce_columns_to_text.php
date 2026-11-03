<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_products', function (Blueprint $table): void {
            $table->longText('delivery_config')->nullable()->change();
        });

        Schema::table('commerce_order_lines', function (Blueprint $table): void {
            $table->longText('delivery_snapshot')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep encrypted values in text columns; converting them back to JSON would corrupt existing ciphertext.
    }
};
