<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_delivery_tasks', function (Blueprint $table): void {
            $table->string('revocation_status', 24)->nullable();
            $table->unsignedInteger('revocation_attempt')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('commerce_delivery_tasks', function (Blueprint $table): void {
            $table->dropColumn(['revocation_status', 'revocation_attempt']);
        });
    }
};
