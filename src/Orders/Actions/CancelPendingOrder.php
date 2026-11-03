<?php

declare(strict_types=1);

namespace Fayne\Commerce\Orders\Actions;

use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Inventory\Actions\ReleaseStockReservation;
use Fayne\Commerce\Inventory\Models\StockReservation;
use Fayne\Commerce\Orders\Enums\OrderStatus;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Support\Outbox\OutboxMessage;
use Fayne\Promotion\Models\CouponReservation;
use Fayne\Promotion\Services\CouponService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CancelPendingOrder
{
    public function __construct(
        private TenantContext $tenant,
        private ReleaseStockReservation $releaseStock,
        private CouponService $coupons,
    ) {}

    public function execute(string $orderId, string $reason): Order
    {
        $tenantId = (int) $this->tenant->requireId();
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A cancellation reason is required.']);
        }

        return DB::transaction(function () use ($tenantId, $orderId, $reason): Order {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->status === OrderStatus::CANCELLED->value) {
                return $order;
            }
            if ($order->status !== OrderStatus::AWAITING_PAYMENT->value || $order->payment_status !== 'pending') {
                throw ValidationException::withMessages(['order' => 'Only an unpaid pending order can be cancelled.']);
            }

            $reservations = StockReservation::query()->where('source_type', 'commerce_order')->where('source_id', $orderId)->orderBy('variant_id')->get();
            foreach ($reservations as $reservation) {
                $this->releaseStock->execute((int) $reservation->getKey());
            }
            if ($order->coupon_reservation_id) {
                $coupon = CouponReservation::query()->whereKey($order->coupon_reservation_id)->firstOrFail();
                $this->coupons->release($tenantId, (string) $coupon->getKey());
            }
            $order->update(['status' => OrderStatus::CANCELLED->value, 'payment_status' => 'cancelled', 'cancellation_reason' => $reason]);
            OutboxMessage::query()->create([
                'event_key' => 'order.cancelled:'.$orderId,
                'event_type' => 'commerce.order.cancelled',
                'payload' => ['order_id' => $orderId, 'reason' => $reason],
                'available_at' => now(),
            ]);

            return $order->refresh();
        }, attempts: 3);
    }
}
