<?php

declare(strict_types=1);

namespace Fayne\Commerce\Returns\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Fayne\Commerce\Returns\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RefundRequest extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'commerce_refund_requests';

    protected $fillable = ['order_id', 'status', 'currency', 'amount', 'reason', 'idempotency_key', 'request_fingerprint', 'requested_by', 'reviewed_by', 'provider_reference', 'failure_reason'];

    protected function casts(): array
    {
        return ['status' => RefundStatus::class, 'amount' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RefundRequestLine::class, 'refund_request_id');
    }
}
