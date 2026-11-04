<?php

declare(strict_types=1);

namespace Fayne\Commerce\Inventory\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Fayne\Commerce\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class StockMovement extends Model
{
    use BelongsToTenant;

    protected $table = 'commerce_stock_movements';

    protected $fillable = ['variant_id', 'source_type', 'source_id', 'reason', 'on_hand_delta', 'reserved_delta'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Stock movements are immutable.'));
        self::deleting(fn () => throw new LogicException('Stock movements are immutable.'));
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    protected function casts(): array
    {
        return ['on_hand_delta' => 'integer', 'reserved_delta' => 'integer'];
    }
}
