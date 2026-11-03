<?php

declare(strict_types=1);

namespace Fayne\Commerce\Fulfillment\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Fayne\Commerce\Orders\Models\Order;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Shipment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'commerce_shipments';

    protected $fillable = ['order_id', 'carrier', 'tracking_number', 'status', 'idempotency_key', 'request_fingerprint', 'shipped_at'];

    protected function casts(): array
    {
        return ['shipped_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentLine::class, 'shipment_id');
    }
}
