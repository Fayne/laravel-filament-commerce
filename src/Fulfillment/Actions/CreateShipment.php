<?php

declare(strict_types=1);

namespace Fayne\Commerce\Fulfillment\Actions;

use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Catalog\Enums\ProductType;
use Fayne\Commerce\Fulfillment\Models\Shipment;
use Fayne\Commerce\Fulfillment\Models\ShipmentLine;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Orders\Models\OrderLine;
use Fayne\Commerce\Returns\Enums\RefundStatus;
use Fayne\Commerce\Returns\Models\RefundRequestLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateShipment
{
    public function __construct(private TenantContext $tenant) {}

    /** @param list<array{order_line_id: int, quantity: int}> $lines */
    public function execute(Order $order, array $lines, ?string $carrier, ?string $trackingNumber, string $idempotencyKey): Shipment
    {
        $tenantId = (int) $this->tenant->requireId();
        if ((string) $order->tenant_id !== (string) $tenantId || $lines === [] || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['shipment' => 'A tenant-owned order, shipment lines, and idempotency key are required.']);
        }
        $normalized = [];
        foreach ($lines as $index => $line) {
            $lineId = (int) ($line['order_line_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);
            if ($lineId < 1 || $quantity < 1 || isset($normalized[$lineId])) {
                throw ValidationException::withMessages(['lines.'.$index => 'Each order line must appear once with a positive quantity.']);
            }
            $normalized[$lineId] = $quantity;
        }
        ksort($normalized);
        $fingerprint = hash('sha256', json_encode([$order->getKey(), $normalized, $carrier, $trackingNumber], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($order, $normalized, $carrier, $trackingNumber, $idempotencyKey, $fingerprint): Shipment {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $existing = Shipment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for a different shipment.']);
                }

                return $existing->load('lines');
            }
            if (! in_array($lockedOrder->payment_status, ['paid', 'not_required'], true) || $lockedOrder->status !== 'confirmed') {
                throw ValidationException::withMessages(['order' => 'Only paid or zero-value confirmed orders can be shipped.']);
            }

            $orderLines = OrderLine::query()->where('order_id', $lockedOrder->getKey())->whereIn('id', array_keys($normalized))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($orderLines->count() !== count($normalized)) {
                throw ValidationException::withMessages(['lines' => 'A selected order line does not belong to this order.']);
            }
            $shipped = ShipmentLine::query()->selectRaw('order_line_id, SUM(quantity) as shipped_quantity')
                ->whereIn('order_line_id', array_keys($normalized))->groupBy('order_line_id')->pluck('shipped_quantity', 'order_line_id');
            $refunded = RefundRequestLine::query()
                ->join('commerce_refund_requests as refund_requests', function ($join): void {
                    $join->on('refund_requests.id', '=', 'commerce_refund_request_lines.refund_request_id')
                        ->on('refund_requests.tenant_id', '=', 'commerce_refund_request_lines.tenant_id');
                })
                ->whereIn('commerce_refund_request_lines.order_line_id', array_keys($normalized))
                ->whereIn('refund_requests.status', [
                    RefundStatus::PENDING_REVIEW->value,
                    RefundStatus::PROCESSING->value,
                    RefundStatus::UNKNOWN->value,
                    RefundStatus::SUCCEEDED->value,
                ])
                ->selectRaw('commerce_refund_request_lines.order_line_id, SUM(commerce_refund_request_lines.quantity) as refunded_quantity')
                ->groupBy('commerce_refund_request_lines.order_line_id')
                ->pluck('refunded_quantity', 'order_line_id');
            foreach ($normalized as $lineId => $quantity) {
                $line = $orderLines->get($lineId);
                if ($line->product_type !== ProductType::PHYSICAL->value) {
                    throw ValidationException::withMessages(['lines' => 'Only physical product lines can be shipped.']);
                }
                $remaining = $line->quantity - (int) ($shipped[$lineId] ?? 0) - (int) ($refunded[$lineId] ?? 0);
                if ($quantity > $remaining) {
                    throw ValidationException::withMessages(['lines.'.$lineId => 'Shipment quantity exceeds the remaining unshipped and unrefunded quantity.']);
                }
            }

            $shipment = Shipment::query()->create([
                'order_id' => $lockedOrder->getKey(),
                'carrier' => $carrier,
                'tracking_number' => $trackingNumber,
                'status' => 'shipped',
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'shipped_at' => now(),
            ]);
            foreach ($normalized as $lineId => $quantity) {
                ShipmentLine::query()->create(['shipment_id' => $shipment->getKey(), 'order_line_id' => $lineId, 'quantity' => $quantity]);
            }

            return $shipment->load('lines');
        }, attempts: 3);
    }
}
