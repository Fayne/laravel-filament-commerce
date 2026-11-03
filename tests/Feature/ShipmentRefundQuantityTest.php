<?php

declare(strict_types=1);

use Fayne\Commerce\Fulfillment\Actions\CreateShipment;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Returns\Enums\RefundStatus;
use Fayne\Commerce\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(TestCase::class);

beforeEach(function (): void {
    Schema::table('commerce_orders', function (Blueprint $table): void {
        $table->string('status')->default('confirmed');
        $table->string('payment_status')->default('paid');
    });
    Schema::create('commerce_order_lines', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->ulid('order_id');
        $table->string('product_type');
        $table->unsignedInteger('quantity');
    });
    Schema::create('commerce_shipments', function (Blueprint $table): void {
        $table->ulid('id')->primary();
        $table->unsignedBigInteger('tenant_id');
        $table->ulid('order_id');
        $table->string('carrier')->nullable();
        $table->string('tracking_number')->nullable();
        $table->string('status');
        $table->string('idempotency_key');
        $table->string('request_fingerprint');
        $table->timestamp('shipped_at');
        $table->timestamps();
    });
    Schema::create('commerce_shipment_lines', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->ulid('shipment_id');
        $table->unsignedBigInteger('order_line_id');
        $table->unsignedInteger('quantity');
        $table->timestamps();
    });
    Schema::create('commerce_refund_requests', function (Blueprint $table): void {
        $table->ulid('id')->primary();
        $table->unsignedBigInteger('tenant_id');
        $table->string('status');
    });
    Schema::create('commerce_refund_request_lines', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->ulid('refund_request_id');
        $table->unsignedBigInteger('order_line_id');
        $table->unsignedInteger('quantity');
    });
});

function shipmentRefundFixture(string $refundStatus, int $refundQuantity): array
{
    $tenantId = 0;
    $orderId = (string) Str::ulid();
    DB::table('commerce_orders')->insert([
        'id' => $orderId,
        'tenant_id' => $tenantId,
        'status' => 'confirmed',
        'payment_status' => 'paid',
    ]);
    $lineId = DB::table('commerce_order_lines')->insertGetId([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'product_type' => 'physical',
        'quantity' => 3,
    ]);
    $refundId = (string) Str::ulid();
    DB::table('commerce_refund_requests')->insert([
        'id' => $refundId,
        'tenant_id' => $tenantId,
        'status' => $refundStatus,
    ]);
    DB::table('commerce_refund_request_lines')->insert([
        'tenant_id' => $tenantId,
        'refund_request_id' => $refundId,
        'order_line_id' => $lineId,
        'quantity' => $refundQuantity,
    ]);

    return [Order::query()->findOrFail($orderId), $lineId];
}

test('does not ship quantities reserved by a pending refund request', function (): void {
    [$order, $lineId] = shipmentRefundFixture(RefundStatus::PENDING_REVIEW->value, 2);

    expect(fn () => app(CreateShipment::class)->execute($order, [['order_line_id' => $lineId, 'quantity' => 2]], null, null, 'shipment-pending-refund'))
        ->toThrow(ValidationException::class);

    expect(DB::table('commerce_shipments')->count())->toBe(0);
});

test('does not ship quantities reserved by unknown or successful refunds', function (string $status): void {
    [$order, $lineId] = shipmentRefundFixture($status, 2);

    expect(fn () => app(CreateShipment::class)->execute($order, [['order_line_id' => $lineId, 'quantity' => 2]], null, null, 'shipment-reserved-refund-'.$status))
        ->toThrow(ValidationException::class);
})->with([RefundStatus::UNKNOWN->value, RefundStatus::SUCCEEDED->value]);

test('allows shipping quantities that remain after a refund reservation', function (): void {
    [$order, $lineId] = shipmentRefundFixture(RefundStatus::PROCESSING->value, 2);

    $shipment = app(CreateShipment::class)->execute($order, [['order_line_id' => $lineId, 'quantity' => 1]], null, null, 'shipment-unreserved-quantity');

    expect($shipment->lines->first()->quantity)->toBe(1)
        ->and(DB::table('commerce_shipments')->count())->toBe(1);
});

test('does not reserve shipment quantity for a rejected refund request', function (): void {
    [$order, $lineId] = shipmentRefundFixture(RefundStatus::REJECTED->value, 2);

    $shipment = app(CreateShipment::class)->execute($order, [['order_line_id' => $lineId, 'quantity' => 3]], null, null, 'shipment-rejected-refund');

    expect($shipment->lines->first()->quantity)->toBe(3);
});

test('does not reserve shipment quantity for a failed refund request', function (): void {
    [$order, $lineId] = shipmentRefundFixture(RefundStatus::FAILED->value, 2);

    $shipment = app(CreateShipment::class)->execute($order, [['order_line_id' => $lineId, 'quantity' => 3]], null, null, 'shipment-failed-refund');

    expect($shipment->lines->first()->quantity)->toBe(3);
});
