<?php

declare(strict_types=1);

namespace Fayne\Commerce\Fulfillment\Actions;

use Fayne\Commerce\Fulfillment\DeliveryHandlerRegistry;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class ProcessDeliveryTask
{
    public function __construct(
        private ClaimDeliveryTask $claimTask,
        private DeliveryHandlerRegistry $handlers,
        private RecordDeliveryResult $recordResult,
    ) {}

    public function execute(string $taskId): ?DeliveryTask
    {
        $task = $this->claimTask->execute($taskId);
        if (! $task) {
            return null;
        }

        try {
            $handler = $this->handlers->resolve($task->handler_key);
        } catch (Throwable $exception) {
            Log::warning('Commerce delivery handler is unavailable.', ['exception_class' => $exception::class]);

            return $this->recordResult->execute($taskId, 'failed', failureReason: 'Delivery handler is unavailable.');
        }

        try {
            $result = $handler->deliver($task, $task->idempotency_key);
        } catch (Throwable $exception) {
            Log::warning('Commerce delivery result is unknown after handler error.', ['exception_class' => $exception::class]);

            return $this->recordResult->execute($taskId, 'unknown');
        }

        if (! is_array($result)
            || ! in_array($result['status'] ?? null, ['succeeded', 'failed', 'unknown'], true)
            || (isset($result['external_reference']) && (! is_string($result['external_reference']) || strlen($result['external_reference']) > 191))
            || (isset($result['result']) && ! is_array($result['result']))
            || (isset($result['failure_reason']) && ! is_string($result['failure_reason']))
            || ($result['status'] === 'failed' && trim((string) ($result['failure_reason'] ?? '')) === '')) {
            return $this->recordResult->execute($taskId, 'unknown');
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
