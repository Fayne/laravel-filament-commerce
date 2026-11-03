<?php

declare(strict_types=1);

namespace Fayne\Commerce\Fulfillment\Actions;

use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Fulfillment\Enums\DeliveryTaskStatus;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RecordDeliveryResult
{
    public function __construct(private TenantContext $tenant) {}

    public function execute(string $taskId, string $outcome, ?string $externalReference = null, ?array $result = null, ?string $failureReason = null): DeliveryTask
    {
        $target = DeliveryTaskStatus::tryFrom($outcome);
        if (! in_array($target, [DeliveryTaskStatus::SUCCEEDED, DeliveryTaskStatus::FAILED, DeliveryTaskStatus::UNKNOWN], true)) {
            throw ValidationException::withMessages(['outcome' => 'Delivery outcome must be succeeded, failed, or unknown.']);
        }
        if ($target === DeliveryTaskStatus::FAILED && trim((string) $failureReason) === '') {
            throw ValidationException::withMessages(['failure_reason' => 'A definite failure requires a reason.']);
        }
        $tenantId = (int) $this->tenant->requireId();

        return DB::transaction(function () use ($tenantId, $taskId, $target, $externalReference, $result, $failureReason): DeliveryTask {
            $candidate = DeliveryTask::query()->where('tenant_id', $tenantId)->whereKey($taskId)->firstOrFail();
            Order::query()->where('tenant_id', $tenantId)->whereKey($candidate->order_id)->lockForUpdate()->firstOrFail();
            $task = DeliveryTask::query()->where('tenant_id', $tenantId)->whereKey($taskId)->lockForUpdate()->firstOrFail();
            if ($task->status === $target) {
                if ($externalReference !== null && $task->external_reference !== $externalReference) {
                    throw ValidationException::withMessages(['external_reference' => 'The delivery result reference does not match the recorded result.']);
                }

                return $task;
            }
            if (! in_array($task->status, [DeliveryTaskStatus::PROCESSING, DeliveryTaskStatus::UNKNOWN], true)) {
                throw ValidationException::withMessages(['task' => 'Only a processing or unknown delivery task can receive a result.']);
            }

            $task->update([
                'status' => $target,
                'external_reference' => $externalReference ?? $task->external_reference,
                'result' => $result,
                'failure_reason' => $target === DeliveryTaskStatus::FAILED ? $failureReason : null,
                'completed_at' => $target === DeliveryTaskStatus::UNKNOWN ? null : now(),
            ]);
            OutboxMessage::query()->create([
                'event_key' => 'delivery.'.$target->value.':'.$task->getKey().':'.$task->attempts,
                'event_type' => 'commerce.delivery.'.$target->value,
                'payload' => ['order_id' => $task->order_id, 'delivery_task_id' => $task->getKey()],
                'available_at' => now(),
            ]);

            return $task->refresh();
        }, attempts: 3);
    }
}
