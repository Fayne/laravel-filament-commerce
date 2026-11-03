<?php

declare(strict_types=1);

namespace Fayne\Commerce\Orders\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Fayne\Commerce\Catalog\Enums\ProductType;
use Fayne\Commerce\Customers\Models\Customer;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use Fayne\Commerce\Fulfillment\Models\Shipment;
use Fayne\Commerce\Fulfillment\Models\ShipmentLine;
use Fayne\Commerce\Returns\Models\RefundRequest;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Order extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'commerce_orders';

    protected $fillable = ['customer_id', 'status', 'payment_status', 'payment_provider', 'payment_reference', 'paid_at', 'cancellation_reason', 'currency', 'subtotal_amount', 'shipping_amount', 'discount_amount', 'payable_amount', 'coupon_code', 'coupon_reservation_id', 'idempotency_key', 'request_fingerprint', 'buyer_snapshot', 'shipping_snapshot', 'promotion_snapshot', 'locale', 'reservation_expires_at'];

    protected function casts(): array
    {
        return ['buyer_snapshot' => 'array', 'shipping_snapshot' => 'array', 'promotion_snapshot' => 'array', 'reservation_expires_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'customer_id' => 'integer', 'subtotal_amount' => 'integer', 'shipping_amount' => 'integer', 'discount_amount' => 'integer', 'payable_amount' => 'integer'];
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class, 'order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'order_id');
    }

    public function deliveryTasks(): HasMany
    {
        return $this->hasMany(DeliveryTask::class, 'order_id');
    }

    public function shippedQuantityForLine(int $lineId): int
    {
        if ($this->relationLoaded('shipments')) {
            return (int) $this->shipments->flatMap(fn ($shipment) => $shipment->lines)->where('order_line_id', $lineId)->sum('quantity');
        }

        return (int) ShipmentLine::query()->where('order_line_id', $lineId)->sum('quantity');
    }

    /** @return list<array{id: int|string, status: string, carrier: ?string, tracking_number: ?string, shipped_at: ?string, lines: list<array{order_line_id: int, quantity: int}>}> */
    public function shipmentSummary(): array
    {
        return $this->shipments->map(fn ($shipment): array => [
            'id' => $shipment->getKey(),
            'status' => $shipment->status,
            'carrier' => $shipment->carrier,
            'tracking_number' => $shipment->tracking_number,
            'shipped_at' => $shipment->shipped_at?->toIso8601String(),
            'lines' => $shipment->lines->map(fn ($line): array => [
                'order_line_id' => (int) $line->order_line_id,
                'quantity' => (int) $line->quantity,
            ])->all(),
        ])->all();
    }

    public function fulfillmentStatus(): string
    {
        $physicalLines = $this->lines->where('product_type', ProductType::PHYSICAL->value);
        if ($physicalLines->isEmpty()) {
            return 'not_required';
        }
        $shipped = $physicalLines->sum(fn (OrderLine $line): int => min($line->quantity, $this->shippedQuantityForLine((int) $line->getKey())));
        if ($shipped === 0) {
            return 'unfulfilled';
        }

        return $shipped < $physicalLines->sum('quantity') ? 'partially_shipped' : 'shipped';
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class, 'order_id');
    }
}
