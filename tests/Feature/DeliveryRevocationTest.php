<?php

use Fayne\CMS\Models\Admin;
use Fayne\CMS\Models\Tenant;
use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Fulfillment\Actions\ProcessDeliveryRevocation;
use Fayne\Commerce\Fulfillment\Actions\RequestDeliveryRevocation;
use Fayne\Commerce\Fulfillment\Contracts\DeliveryRevocationStatusResolver;
use Fayne\Commerce\Fulfillment\Contracts\DeliveryRevoker;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

uses(TestCase::class);

test('duplicate requests and duplicate deliveries invoke the revoker once', function (bool $timeout): void {
    $id = DB::table('commerce_delivery_tasks')->insertGetId([
        'tenant_id' => 0, 'order_id' => (string) Str::ulid(), 'order_line_id' => 1,
        'unit_number' => 1, 'handler_key' => 'test', 'config_snapshot' => '{}',
        'status' => 'succeeded', 'idempotency_key' => 'delivery',
    ]);
    config(['commerce.fulfillment.revokers.test' => DeliveryRevoker::class]);
    $revoker = Mockery::mock(DeliveryRevoker::class);
    $call = $revoker->shouldReceive('revoke')->once()->with(Mockery::type(DeliveryTask::class), "commerce:delivery-revoke:{$id}:1");
    if ($timeout) {
        $call->andThrow(new RuntimeException('Timeout'));
    } else {
        $call->andReturn(['status' => 'succeeded']);
    }
    app()->instance(DeliveryRevoker::class, $revoker);

    $request = app(RequestDeliveryRevocation::class);
    $request->execute($id);
    $request->execute($id);
    expect(DB::table('commerce_outbox_messages')->count())->toBe(1);
    $processor = app(ProcessDeliveryRevocation::class);
    $processor->execute($id, 1);
    $processor->execute($id, 1);
    if ($timeout) {
        expect(DeliveryTask::query()->findOrFail($id)->revocation_status)->toBe('unknown');
        $resolver = Mockery::mock(DeliveryRevocationStatusResolver::class);
        $resolver->shouldReceive('resolve')->once()->with(Mockery::type(DeliveryTask::class), "commerce:delivery-revoke:{$id}:1")->andReturn('succeeded');
        config(['commerce.fulfillment.revocation_status_resolvers.test' => DeliveryRevocationStatusResolver::class]);
        app()->instance(DeliveryRevocationStatusResolver::class, $resolver);
        $processor->reconcile($id);
    }

    expect(DeliveryTask::query()->findOrFail($id)->revocation_status)->toBe('succeeded');
})->with([false, true]);

test('recovery marks stale revocations unknown within the requested limit', function (): void {
    $revoker = Mockery::mock(DeliveryRevoker::class);
    $revoker->shouldNotReceive('revoke');
    app()->instance(DeliveryRevoker::class, $revoker);
    config(['commerce.fulfillment.revokers.test' => DeliveryRevoker::class]);

    $ids = [];
    foreach (['stale', 'also-stale', 'fresh', 'pending'] as $state) {
        $ids[$state] = DB::table('commerce_delivery_tasks')->insertGetId([
            'tenant_id' => 0, 'order_id' => (string) Str::ulid(), 'order_line_id' => 1,
            'unit_number' => 1, 'handler_key' => 'test', 'config_snapshot' => '{}',
            'status' => 'succeeded', 'idempotency_key' => $state, 'revocation_attempt' => 1,
            'revocation_status' => $state === 'pending' ? 'pending' : 'processing',
            'updated_at' => $state === 'fresh' ? now() : now()->subHour(),
        ]);
    }

    $this->artisan('commerce:deliveries:recover', ['--limit' => 1])->assertSuccessful();

    expect(DeliveryTask::query()->findOrFail($ids['stale'])->revocation_status)->toBe('unknown')
        ->and(DeliveryTask::query()->findOrFail($ids['also-stale'])->revocation_status)->toBe('processing')
        ->and(DeliveryTask::query()->findOrFail($ids['fresh'])->revocation_status)->toBe('processing')
        ->and(DeliveryTask::query()->findOrFail($ids['pending'])->revocation_status)->toBe('pending');
});

test('the order gate denies revocation without tenant authorization', function (int $orderTenant): void {
    config(['filament-cms.tenancy.enabled' => true]);
    $tenant = (new Tenant)->forceFill(['id' => 1]);
    app(TenantContext::class)->set($tenant);
    $admin = (new Admin)->forceFill(['id' => 1, 'is_active' => true]);
    $order = (new Order)->forceFill(['tenant_id' => $orderTenant]);

    expect(Gate::forUser($admin)->denies('revokeDelivery', $order))->toBeTrue()
        ->and(DB::table('commerce_outbox_messages')->count())->toBe(0);
})->with(['same tenant without authorization' => 1, 'another tenant' => 2]);
