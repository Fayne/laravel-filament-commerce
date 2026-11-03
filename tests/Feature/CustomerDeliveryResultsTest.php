<?php

declare(strict_types=1);

use Fayne\CMS\Models\Tenant;
use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Customers\Models\Customer;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use Fayne\Commerce\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(TestCase::class);

beforeEach(function (): void {
    config(['auth.guards.customer' => ['driver' => 'session', 'provider' => 'customers'],
        'auth.providers.customers' => ['driver' => 'eloquent', 'model' => Customer::class]]);
});

test('guests cannot read delivery results', function (): void {
    $this->getJson('/api/commerce/customer/deliveries')->assertUnauthorized();
});

test('only the current tenant customer receives their successful delivery results', function (): void {
    config(['filament-cms.tenancy.enabled' => true]);
    app(TenantContext::class)->set((new Tenant)->forceFill(['id' => 1]));
    Schema::table('commerce_orders', function (Blueprint $table): void {
        $table->unsignedBigInteger('customer_id')->nullable();
        $table->timestamps();
    });
    Schema::create('commerce_order_lines', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
    });

    foreach ([[1, 7, 'succeeded', 'visible'], [1, 7, 'failed', 'failed-secret'], [1, 8, 'succeeded', 'other-account'], [2, 7, 'succeeded', 'other-tenant'], [1, null, 'succeeded', 'guest-secret']] as [$tenant, $customer, $status, $secret]) {
        $orderId = (string) Str::ulid();
        DB::table('commerce_orders')->insert(['id' => $orderId, 'tenant_id' => $tenant, 'customer_id' => $customer]);
        $task = new DeliveryTask;
        $task->result = ['secret' => $secret];
        DB::table('commerce_delivery_tasks')->insert([
            'tenant_id' => $tenant, 'order_id' => $orderId, 'order_line_id' => 1,
            'unit_number' => 1, 'handler_key' => 'test', 'config_snapshot' => '{}',
            'status' => $status, 'idempotency_key' => $orderId, 'result' => $task->getAttributes()['result'],
        ]);
    }

    $this->actingAs((new Customer)->forceFill(['id' => 7, 'tenant_id' => 1, 'is_active' => true]), 'customer')
        ->getJson('/api/commerce/customer/deliveries')
        ->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonFragment(['secret' => 'visible'])
        ->assertJsonFragment(['status' => 'failed', 'result' => null])
        ->assertDontSee('failed-secret')->assertDontSee('other-account')->assertDontSee('other-tenant')->assertDontSee('guest-secret');
});
