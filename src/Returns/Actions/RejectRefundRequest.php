<?php

declare(strict_types=1);

namespace Fayne\Commerce\Returns\Actions;

use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Returns\Enums\RefundStatus;
use Fayne\Commerce\Returns\Models\RefundRequest;
use Fayne\Commerce\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RejectRefundRequest
{
    public function __construct(private TenantContext $tenant) {}

    public function execute(string $refundRequestId, string $reason, ?int $reviewedBy = null): RefundRequest
    {
        $tenantId = (int) $this->tenant->requireId();
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
        }

        return DB::transaction(function () use ($tenantId, $refundRequestId, $reason, $reviewedBy): RefundRequest {
            $candidate = RefundRequest::query()->where('tenant_id', $tenantId)->whereKey($refundRequestId)->firstOrFail();
            Order::query()->where('tenant_id', $tenantId)->whereKey($candidate->order_id)->lockForUpdate()->firstOrFail();
            $request = RefundRequest::query()->where('tenant_id', $tenantId)->whereKey($refundRequestId)->lockForUpdate()->firstOrFail();
            if ($request->status === RefundStatus::REJECTED) {
                return $request;
            }
            if ($request->status !== RefundStatus::PENDING_REVIEW) {
                throw ValidationException::withMessages(['refund' => 'Only a pending refund request can be rejected.']);
            }

            $request->update(['status' => RefundStatus::REJECTED, 'failure_reason' => $reason, 'reviewed_by' => $reviewedBy]);
            OutboxMessage::query()->create([
                'event_key' => 'refund.rejected:'.$request->getKey(),
                'event_type' => 'commerce.refund.rejected',
                'payload' => ['order_id' => $request->order_id, 'refund_request_id' => $request->getKey(), 'reason' => $reason],
                'available_at' => now(),
            ]);

            return $request->refresh();
        }, attempts: 3);
    }
}
