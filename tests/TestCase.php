<?php

declare(strict_types=1);

namespace Fayne\Commerce\Tests;

use Fayne\Commerce\CommerceServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [CommerceServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('filament-cms.tenancy.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('commerce_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
        });
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
        });
        Schema::create('commerce_outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
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
        });
    }
}
