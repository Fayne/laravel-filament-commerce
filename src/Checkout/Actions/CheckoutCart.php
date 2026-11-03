<?php

declare(strict_types=1);

namespace Fayne\Commerce\Checkout\Actions;

use Fayne\Commerce\Checkout\Contracts\CartCheckout;
use Fayne\Commerce\Checkout\DTO\CartCheckoutData;
use Fayne\Commerce\Orders\Actions\PlaceOrder;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Pricing\Actions\QuoteProducts;
use Fayne\ShoppingCart\Contracts\CartContextResolver;
use Fayne\ShoppingCart\ShoppingCart;
use Illuminate\Validation\ValidationException;

final readonly class CheckoutCart implements CartCheckout
{
    public function __construct(
        private ShoppingCart $cart,
        private QuoteProducts $quotes,
        private PlaceOrder $orders,
        private CartContextResolver $context,
    ) {}

    public function execute(CartCheckoutData $data): Order
    {
        if ((string) $this->context->customerId() !== (string) $data->customerId) {
            throw ValidationException::withMessages(['customer' => 'Checkout customer must match the authenticated cart owner.']);
        }

        return $this->cart->transaction(function () use ($data): Order {
            $cart = $this->cart->getCart(isCheckout: true);
            $selected = $this->cart->selectedItemsForQuote();
            if (! $cart || $selected === []) {
                throw ValidationException::withMessages(['cart' => 'The cart has no selected items.']);
            }

            $lines = array_map(static fn (array $line): array => [
                'variant_id' => (int) $line['sku_id'],
                'quantity' => $line['quantity'],
            ], $selected);
            $quote = $this->quotes->execute(
                requestedLines: $lines,
                shippingAmount: $data->shippingAmount,
                couponCode: $cart->coupon_code,
                customerId: $data->customerId,
                locale: $data->locale,
            );
            $order = $this->orders->execute(
                quote: $quote,
                buyer: $data->buyer,
                shippingAddress: $data->shippingAddress,
                idempotencyKey: $data->idempotencyKey,
                reservationExpiresAt: $data->reservationExpiresAt,
            );
            $this->cart->consumeOrderItems((string) $cart->getKey(), (string) $order->getKey(), $selected);

            return $order;
        });
    }
}
