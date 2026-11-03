<?php

declare(strict_types=1);

namespace Fayne\Commerce\Returns\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RefundRequestLine extends Model
{
    use BelongsToTenant;

    protected $table = 'commerce_refund_request_lines';

    protected $fillable = ['refund_request_id', 'order_line_id', 'quantity', 'amount', 'unit_allocations'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class, 'refund_request_id');
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'amount' => 'integer', 'unit_allocations' => 'array'];
    }
}
