<?php

declare(strict_types=1);

namespace Fayne\Commerce\Catalog\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Fayne\Commerce\Catalog\Enums\ProductType;
use Fayne\Commerce\Fulfillment\DeliveryHandlerRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Product extends Model
{
    use BelongsToTenant;

    protected $table = 'commerce_products';

    protected $fillable = ['type', 'status', 'delivery_handler', 'delivery_config'];

    protected static function booted(): void
    {
        self::saving(function (self $product): void {
            if ($product->type !== ProductType::PHYSICAL && is_string($product->delivery_handler) && $product->delivery_handler !== '') {
                app(DeliveryHandlerRegistry::class)->resolve($product->delivery_handler);
            }
        });
    }

    protected function casts(): array
    {
        return ['type' => ProductType::class, 'delivery_config' => 'encrypted:array'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class, 'product_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id');
    }
}
