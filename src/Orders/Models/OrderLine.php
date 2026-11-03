<?php

declare(strict_types=1);

namespace Fayne\Commerce\Orders\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OrderLine extends Model
{
    use BelongsToTenant;

    protected $table = 'commerce_order_lines';

    protected $fillable = ['order_id', 'variant_id', 'sku', 'product_type', 'product_name', 'option_snapshot', 'quantity', 'unit_amount', 'line_amount', 'discount_amount', 'payable_amount', 'discount_units', 'delivery_snapshot'];

    protected function casts(): array
    {
        return ['option_snapshot' => 'array', 'discount_units' => 'array', 'delivery_snapshot' => 'encrypted:array', 'quantity' => 'integer', 'unit_amount' => 'integer', 'line_amount' => 'integer', 'discount_amount' => 'integer', 'payable_amount' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
