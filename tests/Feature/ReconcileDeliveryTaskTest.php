<?php

declare(strict_types=1);

use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Fulfillment\Actions\ReconcileDeliveryTask;
use Fayne\Commerce\Fulfillment\DeliveryHandlerRegistry;
use Fayne\Commerce\Fulfillment\Enums\DeliveryTaskStatus;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use Fayne\Commerce\Tests\Fakes\TestDeliveryStatusResolver;
use Fayne\Commerce\Tests\TestCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(TestCase::class);

beforeEach(function (): void {
    TestDeliveryStatusResolver::$response = ['status' => 'succeeded'];
    TestDeliveryStatusResolver::$exception = null;
    TestDeliveryStatusResolver::$calls = [];
});

function createUnknownDeliveryTask(string $status = 'unknown'): DeliveryTask
{
    $orderId = (string) Str::ulid();
    DB::table('commerce_orders')->insert(['id' => $orderId, 'tenant_id' => app(TenantContext::class)->requireId()]);

    return DeliveryTask::query()->create([
        'order_id' => $orderId,
        'order_line_id' => 11,
        'unit_number' => 1,
        'handler_key' => 'resolver-handler',
        'config_snapshot' => ['product_id' => 9],
        'status' => $status,
        'attempts' => 1,
        'idempotency_key' => 'delivery-line-11-unit-1',
    ]);
}

function reconcileDeliveryTask(bool $registerResolver = true): ReconcileDeliveryTask
{
    if ($registerResolver) {
        app(DeliveryHandlerRegistry::class)->registerStatusResolver('resolver-handler', TestDeliveryStatusResolver::class);
    }

    return app(ReconcileDeliveryTask::class);
}

test('queries an unknown task and records its confirmed result', function (): void {
    $task = createUnknownDeliveryTask();
    TestDeliveryStatusResolver::$response = [
        'status' => 'succeeded',
        'external_reference' => 'external-456',
        'result' => ['access_id' => 'issued-789'],
    ];

    $resolved = reconcileDeliveryTask()->execute((string) $task->getKey());

    expect($resolved->status)->toBe(DeliveryTaskStatus::SUCCEEDED)
        ->and($resolved->external_reference)->toBe('external-456')
        ->and($resolved->result)->toBe(['access_id' => 'issued-789'])
        ->and(TestDeliveryStatusResolver::$calls)->toBe([$task->getKey()])
        ->and(DB::table('commerce_outbox_messages')->where('event_type', 'commerce.delivery.succeeded')->count())->toBe(1);
});

test('keeps an unresolved external result unknown', function (): void {
    $task = createUnknownDeliveryTask();
    TestDeliveryStatusResolver::$response = ['status' => 'unknown'];

    $resolved = reconcileDeliveryTask()->execute((string) $task->getKey());

    expect($resolved->status)->toBe(DeliveryTaskStatus::UNKNOWN)
        ->and($resolved->completed_at)->toBeNull()
        ->and(DB::table('commerce_outbox_messages')->count())->toBe(0);
});

test('requires a registered status resolver and leaves the task unchanged', function (): void {
    $task = createUnknownDeliveryTask();

    expect(fn () => reconcileDeliveryTask(registerResolver: false)->execute((string) $task->getKey()))
        ->toThrow(ValidationException::class);
    expect($task->fresh()->status)->toBe(DeliveryTaskStatus::UNKNOWN);
});

test('does not query tasks that are not unknown', function (): void {
    $task = createUnknownDeliveryTask(status: 'pending');

    $resolved = reconcileDeliveryTask()->execute((string) $task->getKey());

    expect($resolved)->toBeNull()->and(TestDeliveryStatusResolver::$calls)->toBeEmpty();
});

test('keeps the task unknown when the resolver throws', function (): void {
    $task = createUnknownDeliveryTask();
    TestDeliveryStatusResolver::$exception = new RuntimeException('Provider lookup unavailable.');

    expect(fn () => reconcileDeliveryTask()->execute((string) $task->getKey()))
        ->toThrow(RuntimeException::class);
    expect($task->fresh()->status)->toBe(DeliveryTaskStatus::UNKNOWN);
});

test('rejects a resolver response with no definite failure reason', function (): void {
    $task = createUnknownDeliveryTask();
    TestDeliveryStatusResolver::$response = ['status' => 'failed'];

    expect(fn () => reconcileDeliveryTask()->execute((string) $task->getKey()))
        ->toThrow(ValidationException::class);
    expect($task->fresh()->status)->toBe(DeliveryTaskStatus::UNKNOWN);
});

test('requires tenant context and cannot reconcile another tenants task', function (): void {
    config(['filament-cms.tenancy.enabled' => true]);
    Context::add('filament-cms.tenant_id', 71);
    $task = createUnknownDeliveryTask();
    Context::add('filament-cms.tenant_id', 72);

    expect(fn () => reconcileDeliveryTask()->execute((string) $task->getKey()))
        ->toThrow(ModelNotFoundException::class)
        ->and(TestDeliveryStatusResolver::$calls)->toBeEmpty();
});

test('refuses reconciliation when tenant context is missing', function (): void {
    config(['filament-cms.tenancy.enabled' => true]);
    Context::forget('filament-cms.tenant_id');

    expect(fn () => reconcileDeliveryTask()->execute('unknown-task-id'))
        ->toThrow(ValidationException::class);
});
