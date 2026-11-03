<?php

declare(strict_types=1);

namespace Fayne\Commerce\Fulfillment\Actions;

use Fayne\Commerce\Fulfillment\Contracts\DeliveryRevocationStatusResolver;
use Fayne\Commerce\Fulfillment\Contracts\DeliveryRevoker;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ProcessDeliveryRevocation
{
    public function execute(int $taskId, int $attempt): void
    {
        $query = DeliveryTask::query()->whereKey($taskId)->where('revocation_attempt', $attempt);
        if (! (clone $query)->where('revocation_status', 'pending')->update(['revocation_status' => 'processing'])) {
            return;
        }

        $task = $query->firstOrFail();
        $revokers = config('commerce.fulfillment.revokers', []);
        try {
            $revoker = app($revokers[$task->handler_key] ?? DeliveryRevoker::class);
            $status = $revoker->revoke($task, "commerce:delivery-revoke:{$taskId}:{$attempt}")['status'] ?? 'unknown';
        } catch (Throwable) {
            $status = 'unknown';
        }

        $query->where('revocation_status', 'processing')->update([
            'revocation_status' => in_array($status, ['succeeded', 'failed'], true) ? $status : 'unknown',
        ]);
    }

    public function reconcile(int $taskId): void
    {
        $task = DeliveryTask::query()->where('revocation_status', 'unknown')->findOrFail($taskId);
        $resolvers = config('commerce.fulfillment.revocation_status_resolvers', []);
        $class = $resolvers[$task->handler_key] ?? '';
        if (! is_a($class, DeliveryRevocationStatusResolver::class, true)) {
            throw ValidationException::withMessages(['task' => 'No revocation status resolver is configured.']);
        }

        $attempt = $task->revocation_attempt;
        $status = app($class)->resolve($task, "commerce:delivery-revoke:{$taskId}:{$attempt}");
        if (in_array($status, ['succeeded', 'failed'], true)) {
            DeliveryTask::query()->whereKey($taskId)->where('revocation_attempt', $attempt)
                ->where('revocation_status', 'unknown')->update(['revocation_status' => $status]);
        }
    }
}
