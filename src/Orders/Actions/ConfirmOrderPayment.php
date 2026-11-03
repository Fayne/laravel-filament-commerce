<?php

declare(strict_types=1);

namespace Fayne\Commerce\Orders\Actions;

use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Fulfillment\Actions\CreateDeliveryTasks;
use Fayne\Commerce\Inventory\Actions\CommitStockReservation;
use Fayne\Commerce\Inventory\Enums\StockReservationStatus;
use Fayne\Commerce\Inventory\Models\StockLevel;
use Fayne\Commerce\Inventory\Models\StockReservation;
use Fayne\Commerce\Orders\Contracts\PaymentConfirmation;
use Fayne\Commerce\Orders\Enums\OrderStatus;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Orders\Models\PaymentConfirmation;
use Fayne\Commerce\Support\Outbox\OutboxMessage;
use Fayne\Promotion\Exceptions\ReservationConflictException;
use Fayne\Promotion\Services\CouponService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ConfirmOrderPayment implements PaymentConfirmation
{
    public function __construct(
        private TenantContext $tenant,
        private CommitStockReservation $commitStock,
        private CouponService $coupons,
        private CreateDeliveryTasks $createDeliveryTasks,
    ) {}

    public function execute(string $orderId, string $provider, string $providerReference, int $amount, string $currency): Order
    {
        $tenantId = (int) $this->tenant->requireId();
        if (trim($provider) === '' || trim($providerReference) === '' || $amount < 0) {
            throw ValidationException::withMessages(['payment' => 'A valid provider reference and non-negative amount are required.']);
        }

        return DB::transaction(function () use ($tenantId, $orderId, $provider, $providerReference, $amount, $currency): Order {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $existing = PaymentConfirmation::query()->where('provider', $provider)->where('provider_reference', $providerReference)->lockForUpdate()->first();
            if ($existing) {
                if ((string) $existing->order_id !== $orderId || $existing->amount !== $amount || $existing->currency !== $currency) {
                    throw ValidationException::withMessages(['payment' => 'The provider reference was already recorded with different payment details.']);
                }

                return $order;
            }
            if ($order->payable_amount === 0) {
                throw ValidationException::withMessages(['payment' => 'This order does not require a payment transaction.']);
            }
            if ($order->currency !== $currency || $order->payable_amount !== $amount) {
                throw ValidationException::withMessages(['payment' => 'Payment amount or currency does not match the order.']);
            }
            if ($order->payment_status === 'paid') {
                throw ValidationException::withMessages(['payment' => 'The order already has a different successful payment.']);
            }

            $reservationVariants = StockReservation::query()->where('source_type', 'commerce_order')->where('source_id', $orderId)->orderBy('variant_id')->pluck('variant_id');
            StockLevel::query()->whereIn('variant_id', $reservationVariants)->orderBy('variant_id')->lockForUpdate()->get();
            $reservations = StockReservation::query()->where('source_type', 'commerce_order')->where('source_id', $orderId)->orderBy('variant_id')->lockForUpdate()->get();
            $expired = $order->status !== OrderStatus::AWAITING_PAYMENT->value
                || $reservations->contains(fn (StockReservation $reservation): bool => $reservation->status !== StockReservationStatus::RESERVED || ($reservation->expires_at && $reservation->expires_at->isPast()));

            if (! $expired && $order->coupon_reservation_id) {
                try {
                    $this->coupons->commit($tenantId, (string) $order->coupon_reservation_id);
                } catch (ReservationConflictException) {
                    $expired = true;
                }
            }

            if ($expired) {
                if ($order->coupon_reservation_id) {
                    try {
                        $this->coupons->release($tenantId, (string) $order->coupon_reservation_id);
                    } catch (ReservationConflictException) {
                        // A committed coupon cannot be released; the order still requires payment review.
                    }
                }
                $outcome = 'review';
                $order->update(['status' => OrderStatus::PAYMENT_REVIEW->value, 'payment_status' => 'review', 'payment_provider' => $provider, 'payment_reference' => $providerReference]);
            } else {
                foreach ($reservations as $reservation) {
                    $this->commitStock->execute((int) $reservation->getKey());
                }
                $outcome = 'paid';
                $order->update(['status' => OrderStatus::CONFIRMED->value, 'payment_status' => 'paid', 'payment_provider' => $provider, 'payment_reference' => $providerReference, 'paid_at' => now()]);
                $this->createDeliveryTasks->execute($orderId);
            }

            PaymentConfirmation::query()->create([
                'order_id' => $orderId, 'provider' => $provider, 'provider_reference' => $providerReference,
                'amount' => $amount, 'currency' => $currency, 'outcome' => $outcome,
            ]);
            OutboxMessage::query()->create([
                'event_key' => 'payment.'.$outcome.':'.$provider.':'.$providerReference,
                'event_type' => $outcome === 'paid' ? 'commerce.order.paid' : 'commerce.payment.review',
                'payload' => ['order_id' => $orderId, 'provider_reference' => $providerReference],
                'available_at' => now(),
            ]);

            return $order->refresh();
        }, attempts: 3);
    }
}
