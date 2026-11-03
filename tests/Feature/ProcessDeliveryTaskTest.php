<?php

declare(strict_types=1);

use Fayne\Commerce\Fulfillment\Actions\ProcessDeliveryTask;
use Fayne\Commerce\Fulfillment\DeliveryHandlerRegistry;
use Fayne\Commerce\Fulfillment\Enums\DeliveryTaskStatus;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use Fayne\Commerce\Tests\Fakes\TestDeliveryHandler;
use Fayne\Commerce\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(TestCase::class);

beforeEach(function (): void {
    TestDeliveryHandler::$response = ['status' => 'succeeded'];
    TestDeliveryHandler::$exception = null;
    TestDeliveryHandler::$calls = [];
});

function createDeliveryTask(string $status = 'pending'): DeliveryTask
{
    $orderId = (string) Str::ulid();
    DB::table('commerce_orders')->insert(['id' => $orderId, 'tenant_id' => 0]);

    return DeliveryTask::query()->create([
        'order_id' => $orderId,
        'order_line_id' => 10,
        'unit_number' => 1,
        'handler_key' => 'test-handler',
        'config_snapshot' => ['product_id' => 7],
        'status' => $status,
        'idempotency_key' => 'delivery-line-10-unit-1',
    ]);
}

function processDeliveryTask(bool $registerHandler = true): ProcessDeliveryTask
{
    $registry = app(DeliveryHandlerRegistry::class);
    if ($registerHandler) {
        $registry->register('test-handler', TestDeliveryHandler::class);
    }

    return app(ProcessDeliveryTask::class);
}

test('delivers a pending task and stores the handler result', function (): void {
    $task = createDeliveryTask();
    TestDeliveryHandler::$response = [
        'status' => 'succeeded',
        'external_reference' => 'issued-123',
        'result' => ['license' => 'masked-value'],
    ];

    $processed = processDeliveryTask()->execute((string) $task->getKey());

    expect($processed->status)->toBe(DeliveryTaskStatus::SUCCEEDED)
        ->and($processed->external_reference)->toBe('issued-123')
        ->and($processed->result)->toBe(['license' => 'masked-value'])
        ->and($processed->attempts)->toBe(1)
        ->and(TestDeliveryHandler::$calls)->toBe([['task_id' => $task->getKey(), 'idempotency_key' => 'delivery-line-10-unit-1']]);
});

test('stores definite handler failures for retry', function (): void {
    $task = createDeliveryTask();
    TestDeliveryHandler::$response = ['status' => 'failed', 'failure_reason' => 'Inventory unavailable.'];

    $processed = processDeliveryTask()->execute((string) $task->getKey());

    expect($processed->status)->toBe(DeliveryTaskStatus::FAILED)
        ->and($processed->failure_reason)->toBe('Inventory unavailable.')
        ->and($processed->attempts)->toBe(1);
});

test('records a new outbox event for each repeated definite failure attempt', function (): void {
    $task = createDeliveryTask();
    TestDeliveryHandler::$response = ['status' => 'failed', 'failure_reason' => 'Inventory unavailable.'];
    $processor = processDeliveryTask();

    $processor->execute((string) $task->getKey());
    $retried = $processor->execute((string) $task->getKey());

    expect($retried->status)->toBe(DeliveryTaskStatus::FAILED)
        ->and($retried->attempts)->toBe(2)
        ->and(DB::table('commerce_outbox_messages')->where('event_type', 'commerce.delivery.failed')->count())->toBe(2)
        ->and(TestDeliveryHandler::$calls)->toHaveCount(2);
});

test('records thrown handler errors as unknown and does not redeliver blindly', function (): void {
    $task = createDeliveryTask();
    TestDeliveryHandler::$exception = new RuntimeException('Provider timed out after issuing the key.');
    $processor = processDeliveryTask();

    $processed = $processor->execute((string) $task->getKey());
    $secondAttempt = $processor->execute((string) $task->getKey());

    expect($processed->status)->toBe(DeliveryTaskStatus::UNKNOWN)
        ->and($secondAttempt)->toBeNull()
        ->and(TestDeliveryHandler::$calls)->toHaveCount(1)
        ->and($processed->failure_reason)->toBeNull();
});

test('records malformed handler responses as unknown', function (): void {
    $task = createDeliveryTask();
    TestDeliveryHandler::$response = ['status' => 'failed'];

    $processed = processDeliveryTask()->execute((string) $task->getKey());

    expect($processed->status)->toBe(DeliveryTaskStatus::UNKNOWN)
        ->and($processed->completed_at)->toBeNull();
});

test('marks a task failed when its registered handler is unavailable', function (): void {
    $task = createDeliveryTask();

    $processed = processDeliveryTask(registerHandler: false)->execute((string) $task->getKey());

    expect($processed->status)->toBe(DeliveryTaskStatus::FAILED)
        ->and($processed->failure_reason)->toBe('Delivery handler is unavailable.')
        ->and(TestDeliveryHandler::$calls)->toBeEmpty();
});

test('does not invoke a handler for tasks that cannot be claimed', function (): void {
    $task = createDeliveryTask(status: 'unknown');

    $processed = processDeliveryTask()->execute((string) $task->getKey());

    expect($processed)->toBeNull()->and(TestDeliveryHandler::$calls)->toBeEmpty();
});
