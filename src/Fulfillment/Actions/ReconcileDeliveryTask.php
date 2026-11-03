<?php

declare(strict_types=1);

namespace Fayne\Commerce\Fulfillment\Actions;

use Fayne\Commerce\Fulfillment\DeliveryHandlerRegistry;
use Fayne\Commerce\Fulfillment\Enums\DeliveryTaskStatus;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use Illuminate\Validation\ValidationException;

final readonly class ReconcileDeliveryTask
{
    public function __construct(
        private DeliveryHandlerRegistry $handlers,
        private RecordDeliveryResult $recordResult,
    ) {}

    public function execute(string $taskId): ?DeliveryTask
    {
        $task = DeliveryTask::query()->whereKey($taskId)->firstOrFail();
        if ($task->status !== DeliveryTaskStatus::UNKNOWN) {
            return null;
        }

        $resolver = $this->handlers->resolveStatusResolver($task->handler_key);
        if (! $resolver) {
            throw ValidationException::withMessages(['task' => 'No delivery status resolver is registered for this task.']);
        }

        $result = $resolver->resolve($task);
        if (! in_array($result['status'] ?? null, ['succeeded', 'failed', 'unknown'], true)
            || (isset($result['external_reference']) && (! is_string($result['external_reference']) || strlen($result['external_reference']) > 191))
            || (isset($result['result']) && ! is_array($result['result']))
            || (isset($result['failure_reason']) && ! is_string($result['failure_reason']))
            || ($result['status'] === 'failed' && trim((string) ($result['failure_reason'] ?? '')) === '')) {
            throw ValidationException::withMessages(['task' => 'The delivery status resolver returned an invalid result.']);
        }

        return $this->recordResult->execute(
            taskId: $taskId,
            outcome: $result['status'],
            externalReference: $result['external_reference'] ?? null,
            result: $result['result'] ?? null,
            failureReason: $result['failure_reason'] ?? null,
        );
    }
}
