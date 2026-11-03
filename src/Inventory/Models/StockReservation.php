<?php

declare(strict_types=1);

namespace Fayne\Commerce\Inventory\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Fayne\Commerce\Inventory\Enums\StockReservationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockReservation extends Model
{
    use BelongsToTenant;

    protected $table = 'commerce_stock_reservations';

    protected $fillable = ['variant_id', 'source_type', 'source_id', 'quantity', 'status', 'expires_at', 'committed_at', 'released_at'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'status' => StockReservationStatus::class, 'expires_at' => 'immutable_datetime', 'committed_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    public function stockLevel(): BelongsTo
    {
        return $this->belongsTo(StockLevel::class, 'variant_id', 'variant_id');
    }
}
