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

final readonly class RecordRefundResult
{
    public function __construct(private TenantContext $tenant) {}

    public function execute(string $refundRequestId, string $outcome, ?string $providerReference = null, ?string $failureReason = null): RefundRequest
    {
        $this->tenant->requireId();
        if (! in_array($outcome, ['succeeded', 'failed', 'unknown'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Refund outcome must be succeeded, failed, or unknown.']);
        }

        $tenantId = (int) $this->tenant->requireId();

        return DB::transaction(function () use ($tenantId, $refundRequestId, $outcome, $providerReference, $failureReason): RefundRequest {
            $candidate = RefundRequest::query()->where('tenant_id', $tenantId)->whereKey($refundRequestId)->firstOrFail();
            Order::query()->where('tenant_id', $tenantId)->whereKey($candidate->order_id)->lockForUpdate()->firstOrFail();
            $request = RefundRequest::query()->where('tenant_id', $tenantId)->whereKey($refundRequestId)->lockForUpdate()->firstOrFail();
            $target = RefundStatus::from($outcome);
            if ($request->status === $target) {
                if ($providerReference !== null && $request->provider_reference !== $providerReference) {
                    throw ValidationException::withMessages(['provider_reference' => 'The refund result reference does not match the recorded result.']);
                }

                return $request;
            }
            if (! in_array($request->status, [RefundStatus::PROCESSING, RefundStatus::UNKNOWN], true)) {
                throw ValidationException::withMessages(['refund' => 'Only a processing or unknown refund can receive a gateway result.']);
            }
            if ($outcome === 'failed' && trim((string) $failureReason) === '') {
                throw ValidationException::withMessages(['failure_reason' => 'A definite failure requires a reason.']);
            }

            $request->update([
                'status' => $target,
                'provider_reference' => $providerReference ?? $request->provider_reference,
                'failure_reason' => $outcome === 'failed' ? $failureReason : null,
            ]);
            OutboxMessage::query()->create([
                'event_key' => 'refund.'.$outcome.':'.$request->getKey(),
                'event_type' => 'commerce.refund.'.$outcome,
                'payload' => ['order_id' => $request->order_id, 'refund_request_id' => $request->getKey(), 'amount' => $request->amount, 'currency' => $request->currency],
                'available_at' => now(),
            ]);

            return $request->refresh();
        }, attempts: 3);
    }
}
