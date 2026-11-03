<?php

declare(strict_types=1);

namespace Fayne\Commerce\Catalog\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Fayne\CMS\Support\ContentLocale;
use Fayne\Commerce\Inventory\Models\StockLevel;
use Fayne\Commerce\Tenancy\Actions\TenantCurrency;
use Fayne\ShoppingCart\Contracts\Cartable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

final class ProductVariant extends Model implements Cartable
{
    use BelongsToTenant;

    protected $table = 'commerce_product_variants';

    protected $fillable = ['product_id', 'sku', 'price_amount', 'currency', 'option_values', 'is_active'];

    protected static function booted(): void
    {
        self::saving(function (self $variant): void {
            $currency = app(TenantCurrency::class)->current();
            if ($variant->currency !== null && $variant->currency !== $currency) {
                throw ValidationException::withMessages([
                    'currency' => __('commerce::products.currency_must_match_tenant'),
                ]);
            }
            $variant->currency = $currency;
        });
    }

    protected function casts(): array
    {
        return ['price_amount' => 'integer', 'option_values' => 'array', 'is_active' => 'boolean'];
    }

    public static function findByCodeOrFail(string $code): self
    {
        return self::query()->where('sku', $code)->firstOrFail();
    }

    public function getPriceInMinorUnits(): int
    {
        return $this->price_amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->product?->translations->firstWhere('locale', ContentLocale::default())?->name ?? $this->sku;
    }

    public function getWeight(): null
    {
        return null;
    }

    public function canBePurchased(): bool
    {
        return $this->is_active && $this->product?->status === 'active';
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function stockLevel(): HasOne
    {
        return $this->hasOne(StockLevel::class, 'variant_id');
    }
}
