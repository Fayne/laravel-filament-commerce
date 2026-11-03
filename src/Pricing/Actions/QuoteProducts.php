<?php

declare(strict_types=1);

namespace Fayne\Commerce\Pricing\Actions;

use Fayne\CMS\Support\ContentLocale;
use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Catalog\Enums\ProductType;
use Fayne\Commerce\Catalog\Models\ProductVariant;
use Fayne\Commerce\Customers\Models\Customer;
use Fayne\Commerce\Inventory\Models\StockLevel;
use Fayne\Commerce\Pricing\DTO\CommerceQuote;
use Fayne\Commerce\Tenancy\Actions\TenantCurrency;
use Fayne\Promotion\DTO\CartItem;
use Fayne\Promotion\DTO\Context;
use Fayne\Promotion\Engine\PromotionEngine;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final readonly class QuoteProducts
{
    public function __construct(private TenantContext $tenant, private TenantCurrency $currency, private PromotionEngine $promotions) {}

    /** @param list<array{variant_id: int, quantity: int}> $requestedLines */
    public function execute(array $requestedLines, int $shippingAmount, ?string $couponCode = null, ?int $customerId = null, ?string $locale = null): CommerceQuote
    {
        if ($requestedLines === [] || $shippingAmount < 0) {
            throw ValidationException::withMessages(['lines' => 'At least one line and a non-negative shipping amount are required.']);
        }

        $tenantId = (int) $this->tenant->requireId();
        if ($customerId !== null && ! Customer::query()->whereKey($customerId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['customer' => 'The customer is unavailable in this tenant.']);
        }
        $currency = $this->currency->current();
        $ids = array_column($requestedLines, 'variant_id');
        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['lines' => 'Each SKU may appear only once in a quote.']);
        }

        $variants = ProductVariant::query()->with(['product.translations'])
            ->whereIn('id', $ids)->where('is_active', true)->get()->keyBy('id');
        if ($variants->count() !== count($requestedLines)) {
            throw ValidationException::withMessages(['lines' => 'A requested SKU is unavailable in this tenant.']);
        }

        $lines = [];
        $promotionItems = [];
        $subtotal = 0;
        $locale = ContentLocale::resolve($locale);
        foreach (array_values($requestedLines) as $index => $requested) {
            $quantity = (int) ($requested['quantity'] ?? 0);
            if ($quantity < 1) {
                throw ValidationException::withMessages(['lines.'.$index.'.quantity' => 'Quantity must be positive.']);
            }
            $variant = $variants->get($requested['variant_id']);
            if ($variant->currency !== $currency) {
                throw ValidationException::withMessages(['currency' => 'The SKU currency does not match the tenant currency.']);
            }
            $product = $variant->product;
            if ($product->type === ProductType::PHYSICAL) {
                $stock = StockLevel::query()->where('variant_id', $variant->getKey())->first();
                if (! $stock || $stock->available() < $quantity) {
                    throw ValidationException::withMessages(['lines.'.$index => 'Insufficient available stock.']);
                }
            }
            if ($product->status !== 'active') {
                throw ValidationException::withMessages(['lines.'.$index => 'The product is not available for purchase.']);
            }
            $name = $product->translations->firstWhere('locale', $locale)?->name;
            if (! $name) {
                throw ValidationException::withMessages(['lines.'.$index => 'The product has no name in the default content locale.']);
            }
            $lineId = (string) $variant->getKey();
            if ($variant->price_amount > intdiv(PHP_INT_MAX, $quantity)) {
                throw ValidationException::withMessages(['lines' => 'Quote amount exceeds the supported integer range.']);
            }
            $lineAmount = $variant->price_amount * $quantity;
            if ($lineAmount > PHP_INT_MAX - $subtotal) {
                throw ValidationException::withMessages(['lines' => 'Quote amount exceeds the supported integer range.']);
            }
            $subtotal += $lineAmount;
            $lines[] = ['line_id' => $lineId, 'variant_id' => $variant->getKey(), 'sku' => $variant->sku, 'name' => $name, 'quantity' => $quantity, 'unit_amount' => $variant->price_amount, 'line_amount' => $lineAmount];
            $promotionItems[] = new CartItem($lineId, $variant->sku, $variant->price_amount, $quantity);
        }

        if (! collect($variants)->contains(fn (ProductVariant $variant): bool => $variant->product->type === ProductType::PHYSICAL) && $shippingAmount !== 0) {
            throw ValidationException::withMessages(['shipping' => 'Shipping must be zero when the quote contains no physical products.']);
        }

        $promotion = $this->promotions->calculate(new Context(
            tenantId: $tenantId,
            currency: $currency,
            subtotal: $subtotal,
            shipping: $shippingAmount,
            items: $promotionItems,
            couponCode: $couponCode,
            now: Carbon::now()->toDateTimeImmutable(),
            customerId: $customerId,
        ));

        return new CommerceQuote($tenantId, $currency, $lines, $subtotal, $shippingAmount, $promotion, $promotion->payable, $couponCode, $customerId, $locale);
    }
}
