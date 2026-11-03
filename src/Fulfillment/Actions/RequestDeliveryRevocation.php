<?php

declare(strict_types=1);

namespace Fayne\Commerce\Fulfillment\Actions;

use Fayne\Commerce\Fulfillment\Contracts\DeliveryRevoker;
use Fayne\Commerce\Fulfillment\Enums\DeliveryTaskStatus;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use Fayne\Commerce\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RequestDeliveryRevocation
{
    public function execute(int $taskId, bool $retry = false): DeliveryTask
    {
        return DB::transaction(function () use ($taskId, $retry): DeliveryTask {
            $task = DeliveryTask::query()->lockForUpdate()->findOrFail($taskId);
            if ($task->revocation_status !== null && ! ($retry && $task->revocation_status === 'failed')) {
                return $task;
            }

            $revokers = config('commerce.fulfillment.revokers', []);
            if ($task->status !== DeliveryTaskStatus::SUCCEEDED || ! is_a($revokers[$task->handler_key] ?? '', DeliveryRevoker::class, true)) {
                throw ValidationException::withMessages(['task' => 'This delivery cannot be revoked.']);
            }

            $attempt = (int) $task->revocation_attempt + 1;
            $task->forceFill(['revocation_status' => 'pending', 'revocation_attempt' => $attempt])->save();
            OutboxMessage::query()->create([
                'event_key' => "commerce:delivery-revoke:{$taskId}:{$attempt}",
                'event_type' => 'commerce.delivery.revocation_requested',
                'payload' => ['delivery_task_id' => $taskId, 'attempt' => $attempt],
                'available_at' => now(),
            ]);

            return $task;
        }, attempts: 3);
    }
}
