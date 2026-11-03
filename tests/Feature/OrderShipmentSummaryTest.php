<?php

declare(strict_types=1);

use Fayne\Commerce\Fulfillment\Models\Shipment;
use Fayne\Commerce\Fulfillment\Models\ShipmentLine;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Tests\TestCase;
use Illuminate\Database\Eloquent\Collection;

uses(TestCase::class);

test('builds an order shipment summary with partial line quantities', function (): void {
    $shipment = Shipment::make([
        'carrier' => 'SF Express',
        'tracking_number' => 'SF123',
        'status' => 'shipped',
        'shipped_at' => '2026-11-03 09:15:00',
    ]);
    $shipment->setAttribute('id', '01JTESTSHIPMENT000000000001');
    $shipment->setRelation('lines', new Collection([
        ShipmentLine::make(['order_line_id' => 31, 'quantity' => 2]),
    ]));
    $order = new Order;
    $order->setRelation('shipments', new Collection([$shipment]));

    expect($order->shipmentSummary())->toBe([[
        'id' => '01JTESTSHIPMENT000000000001',
        'status' => 'shipped',
        'carrier' => 'SF Express',
        'tracking_number' => 'SF123',
        'shipped_at' => '2026-11-03T09:15:00+00:00',
        'lines' => [['order_line_id' => 31, 'quantity' => 2]],
    ]]);
});
