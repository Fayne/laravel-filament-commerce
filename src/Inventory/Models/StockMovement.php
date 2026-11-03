<?php

declare(strict_types=1);

namespace Fayne\Commerce\Inventory\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
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

    protected function casts(): array
    {
        return ['on_hand_delta' => 'integer', 'reserved_delta' => 'integer'];
    }
}
