<?php

declare(strict_types=1);

namespace Fayne\Commerce\Inventory\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Fayne\Commerce\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockLevel extends Model
{
    use BelongsToTenant;

    protected $table = 'commerce_stock_levels';

    protected $fillable = ['variant_id', 'on_hand', 'reserved'];

    protected function casts(): array
    {
        return ['on_hand' => 'integer', 'reserved' => 'integer'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function available(): int
    {
        return $this->on_hand - $this->reserved;
    }
}
