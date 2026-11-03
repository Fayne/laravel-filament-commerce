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

final readonly class ApproveRefundRequest
{
    public function __construct(private TenantContext $tenant) {}

    public function execute(string $refundRequestId, ?int $reviewedBy = null): RefundRequest
    {
        $tenantId = (int) $this->tenant->requireId();

        return DB::transaction(function () use ($tenantId, $refundRequestId, $reviewedBy): RefundRequest {
            $candidate = RefundRequest::query()->where('tenant_id', $tenantId)->whereKey($refundRequestId)->firstOrFail();
            Order::query()->where('tenant_id', $tenantId)->whereKey($candidate->order_id)->lockForUpdate()->firstOrFail();
            $request = RefundRequest::query()->where('tenant_id', $tenantId)->whereKey($refundRequestId)->lockForUpdate()->firstOrFail();
            if ($request->status === RefundStatus::PROCESSING) {
                return $request;
            }
            if ($request->status !== RefundStatus::PENDING_REVIEW) {
                throw ValidationException::withMessages(['refund' => 'Only a pending refund request can be approved.']);
            }

            $request->update(['status' => RefundStatus::PROCESSING, 'reviewed_by' => $reviewedBy]);
            OutboxMessage::query()->create([
                'event_key' => 'refund.approved:'.$request->getKey(),
                'event_type' => 'commerce.refund.approved',
                'payload' => ['order_id' => $request->order_id, 'refund_request_id' => $request->getKey(), 'amount' => $request->amount, 'currency' => $request->currency],
                'available_at' => now(),
            ]);

            return $request->refresh();
        }, attempts: 3);
    }
}
