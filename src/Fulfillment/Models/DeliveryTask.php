<?php

declare(strict_types=1);

namespace Fayne\Commerce\Fulfillment\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Fayne\Commerce\Fulfillment\Enums\DeliveryTaskStatus;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Orders\Models\OrderLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeliveryTask extends Model
{
    use BelongsToTenant;

    protected $table = 'commerce_delivery_tasks';

    protected $fillable = ['order_id', 'order_line_id', 'unit_number', 'handler_key', 'config_snapshot', 'status', 'idempotency_key', 'external_reference', 'result', 'failure_reason', 'attempts', 'completed_at'];

    protected function casts(): array
    {
        return ['config_snapshot' => 'encrypted:array', 'status' => DeliveryTaskStatus::class, 'result' => 'encrypted:array', 'attempts' => 'integer', 'completed_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }
}
