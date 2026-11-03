<?php

declare(strict_types=1);

namespace Fayne\Commerce\Orders\Actions;

use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Catalog\Enums\ProductType;
use Fayne\Commerce\Catalog\Models\Product;
use Fayne\Commerce\Catalog\Models\ProductVariant;
use Fayne\Commerce\Customers\Models\Customer;
use Fayne\Commerce\Fulfillment\Actions\CreateDeliveryTasks;
use Fayne\Commerce\Inventory\Actions\CommitStockReservation;
use Fayne\Commerce\Inventory\Actions\ReserveStock;
use Fayne\Commerce\Inventory\Models\StockLevel;
use Fayne\Commerce\Inventory\Models\StockReservation;
use Fayne\Commerce\Orders\Enums\OrderStatus;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Pricing\DTO\CommerceQuote;
use Fayne\Commerce\Support\Outbox\OutboxMessage;
use Fayne\Commerce\Tenancy\Actions\TenantCurrency;
use Fayne\Promotion\DTO\AppliedPromotion;
use Fayne\Promotion\Services\CouponService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class PlaceOrder
{
    public function __construct(
        private TenantContext $tenant,
        private TenantCurrency $currency,
        private ReserveStock $reserveStock,
        private CouponService $coupons,
    ) {}

    /**
     * @param  array{name: string, email?: string|null, phone?: string|null}  $buyer
     * @param  array<string, mixed>|null  $shippingAddress
     */
    public function execute(
        CommerceQuote $quote,
        array $buyer,
        ?array $shippingAddress,
        string $idempotencyKey,
        Carbon $reservationExpiresAt,
    ): Order {
        $tenantId = (int) $this->tenant->requireId();
        if ($quote->tenantId !== $tenantId || $quote->currency !== $this->currency->current()) {
            throw ValidationException::withMessages(['quote' => 'The quote does not match the active tenant or currency.']);
        }
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128 || $reservationExpiresAt->lte(now())) {
            throw ValidationException::withMessages(['checkout' => 'A valid idempotency key and future reservation expiry are required.']);
        }
        $buyerSnapshot = $this->normalizeBuyer($buyer);

        $fingerprint = hash('sha256', json_encode([
            'tenant' => $tenantId, 'currency' => $quote->currency,
            'lines' => array_map(fn (array $line): array => [$line['variant_id'], $line['quantity']], $quote->lines),
            'shipping' => $quote->shipping, 'coupon' => $quote->couponCode,
            'buyer' => $buyerSnapshot, 'shipping_address' => $shippingAddress,
            'customer_id' => $quote->customerId, 'locale' => $quote->locale,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($quote, $tenantId, $buyerSnapshot, $shippingAddress, $idempotencyKey, $reservationExpiresAt, $fingerprint): Order {
            $existing = Order::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for a different checkout request.']);
                }

                return $existing->load('lines');
            }

            if ($quote->customerId !== null) {
                Customer::query()->whereKey($quote->customerId)->where('is_active', true)->lockForUpdate()->firstOrFail();
            }

            $variantIds = collect($quote->lines)->pluck('variant_id')->sort()->values()->all();
            $variants = ProductVariant::query()->with('product')->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($variants->count() !== count($variantIds)) {
                throw ValidationException::withMessages(['quote' => 'A quoted SKU is no longer available.']);
            }
            $products = Product::query()->whereIn('id', $variants->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $hasPhysical = $variants->contains(fn (ProductVariant $variant): bool => $products->get($variant->product_id)?->type === ProductType::PHYSICAL);
            if ($hasPhysical && (! $shippingAddress || $shippingAddress === [])) {
                throw ValidationException::withMessages(['shipping_address' => 'A shipping address is required for physical products.']);
            }
            foreach ($quote->lines as $line) {
                $variant = $variants->get($line['variant_id']);
                $product = $products->get($variant->product_id);
                if (! $variant->is_active || ! $product || $variant->currency !== $quote->currency || $variant->price_amount !== $line['unit_amount'] || $product->status !== 'active') {
                    throw ValidationException::withMessages(['quote' => 'A product price or availability changed; create a new quote.']);
                }
            }
            if ($hasPhysical) {
                $levels = StockLevel::query()->whereIn('variant_id', $variantIds)->orderBy('variant_id')->lockForUpdate()->get()->keyBy('variant_id');
                foreach ($quote->lines as $line) {
                    $variant = $variants->get($line['variant_id']);
                    if ($products->get($variant->product_id)?->type === ProductType::PHYSICAL && (! $levels->has($variant->getKey()) || $levels->get($variant->getKey())->available() < $line['quantity'])) {
                        throw ValidationException::withMessages(['stock' => 'Available stock changed; create a new quote.']);
                    }
                }
            }

            $reservationId = null;
            $orderId = (string) str()->ulid();
            if ($quote->couponCode !== null) {
                $reservation = $this->coupons->reserve(
                    tenantId: $tenantId,
                    currency: $quote->currency,
                    code: $quote->couponCode,
                    sourceType: 'commerce_order',
                    sourceId: $orderId,
                    idempotencyKey: $idempotencyKey,
                    expiresAt: $reservationExpiresAt,
                    customerId: $quote->customerId,
                );
                $reservationId = $reservation->getKey();
            }

            $order = new Order;
            $order->id = $orderId;
            $order->fill([
                'customer_id' => $quote->customerId,
                'status' => 'awaiting_payment',
                'currency' => $quote->currency,
                'subtotal_amount' => $quote->subtotal,
                'shipping_amount' => $quote->shipping,
                'discount_amount' => $quote->promotion->totalDiscount,
                'payable_amount' => $quote->payable,
                'coupon_code' => $quote->couponCode,
                'coupon_reservation_id' => $reservationId,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'buyer_snapshot' => $buyerSnapshot,
                'shipping_snapshot' => $shippingAddress,
                'promotion_snapshot' => $this->promotionSnapshot($quote->promotion->appliedPromotions),
                'locale' => $quote->locale,
                'reservation_expires_at' => $reservationExpiresAt,
            ]);
            $order->save();

            foreach ($quote->lines as $line) {
                $variant = $variants->get($line['variant_id']);
                $product = $products->get($variant->product_id);
                $allocation = collect($quote->promotion->lineAllocations)->firstWhere('lineId', (string) $variant->getKey());
                $order->lines()->create([
                    'variant_id' => $variant->getKey(),
                    'sku' => $variant->sku,
                    'product_type' => $product->type->value,
                    'product_name' => $line['name'],
                    'option_snapshot' => $variant->option_values,
                    'quantity' => $line['quantity'],
                    'unit_amount' => $line['unit_amount'],
                    'line_amount' => $line['line_amount'],
                    'discount_amount' => $allocation?->discount ?? 0,
                    'payable_amount' => $allocation?->netAmount ?? $line['line_amount'],
                    'discount_units' => $allocation?->units ?? [],
                    'delivery_snapshot' => $product->type === ProductType::PHYSICAL ? null : ['handler' => $product->delivery_handler, 'config' => $product->delivery_config],
                ]);
                if ($product->type === ProductType::PHYSICAL) {
                    $this->reserveStock->execute($variant, $line['quantity'], 'commerce_order', $orderId, $reservationExpiresAt);
                }
            }

            $eventType = 'commerce.order.placed';
            $eventKey = 'order.placed:'.$orderId;
            if ($quote->payable === 0) {
                $reservations = StockReservation::query()
                    ->where('source_type', 'commerce_order')->where('source_id', $orderId)->orderBy('variant_id')->get();
                foreach ($reservations as $reservation) {
                    app(CommitStockReservation::class)->execute((int) $reservation->getKey());
                }
                if ($reservationId !== null) {
                    $this->coupons->commit($tenantId, (string) $reservationId);
                }
                $order->update(['status' => OrderStatus::CONFIRMED->value, 'payment_status' => 'not_required']);
                app(CreateDeliveryTasks::class)->execute($orderId);
                $eventType = 'commerce.order.confirmed';
                $eventKey = 'order.confirmed:'.$orderId;
            }

            OutboxMessage::query()->create([
                'event_key' => $eventKey,
                'event_type' => $eventType,
                'payload' => ['order_id' => $orderId],
                'available_at' => now(),
            ]);

            return $order->load('lines');
        }, attempts: 3);
    }

    private function normalizeBuyer(array $buyer): array
    {
        $name = trim((string) ($buyer['name'] ?? ''));
        $email = isset($buyer['email']) ? strtolower(trim((string) $buyer['email'])) : null;
        $phone = isset($buyer['phone']) ? trim((string) $buyer['phone']) : null;
        if ($name === '' || (! $email && ! $phone) || ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw ValidationException::withMessages(['buyer' => 'A buyer name and a valid email or phone number are required.']);
        }

        return array_filter(['name' => $name, 'email' => $email, 'phone' => $phone], fn ($value) => $value !== null && $value !== '');
    }

    /** @param list<AppliedPromotion> $promotions */
    private function promotionSnapshot(array $promotions): array
    {
        return array_map(fn (AppliedPromotion $promotion): array => [
            'promotion_id' => $promotion->promotionId,
            'name' => $promotion->name,
            'action_type' => $promotion->actionType,
            'item_discount' => $promotion->itemDiscount,
            'shipping_discount' => $promotion->shippingDiscount,
        ], $promotions);
    }
}
