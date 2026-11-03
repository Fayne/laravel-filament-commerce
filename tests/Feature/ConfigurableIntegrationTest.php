<?php

declare(strict_types=1);

use Fayne\Commerce\Cart\CommerceCartContextResolver;
use Fayne\Commerce\Cart\CommerceSkuQuery;
use Fayne\Commerce\Catalog\Models\ProductVariant;
use Fayne\Commerce\Orders\Actions\ConfirmOrderPayment;
use Fayne\Commerce\Orders\Contracts\PaymentConfirmation;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Tenancy\Actions\TenantCurrency;
use Fayne\Commerce\Tests\TestCase;
use Fayne\ShoppingCart\Contracts\CartContextResolver;
use Fayne\ShoppingCart\Contracts\CartCurrencyResolver;
use Fayne\ShoppingCart\Contracts\CartSkuQuery;
use Fayne\ShoppingCart\Support\CartSku;

uses(TestCase::class);

test('configures the commerce SKU model and tenant-safe shopping cart adapters by default', function (): void {
    expect(config('commerce.cart.sku_model'))->toBe(ProductVariant::class)
        ->and(CartSku::model())->toBe(ProductVariant::class)
        ->and(app(CartSkuQuery::class))->toBeInstanceOf(CommerceSkuQuery::class)
        ->and(app(CartCurrencyResolver::class))->toBeInstanceOf(TenantCurrency::class)
        ->and(app(CartContextResolver::class))->toBeInstanceOf(CommerceCartContextResolver::class);
});

test('binds the configured default payment confirmation action', function (): void {
    expect(app()->bound(PaymentConfirmation::class))->toBeTrue()
        ->and(config('commerce.actions.payment_confirmation'))->toBe(ConfirmOrderPayment::class);
});

test('allows the host to replace the payment confirmation action through config', function (): void {
    config(['commerce.actions.payment_confirmation' => FakePaymentConfirmation::class]);

    expect(app(PaymentConfirmation::class))->toBeInstanceOf(FakePaymentConfirmation::class);
});

final class FakePaymentConfirmation implements PaymentConfirmation
{
    public function execute(string $orderId, string $provider, string $providerReference, int $amount, string $currency): Order
    {
        return new Order;
    }
}
