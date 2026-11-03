<?php

declare(strict_types=1);

namespace Fayne\Commerce\Fulfillment\Actions;

use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Fulfillment\Enums\DeliveryTaskStatus;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use Fayne\Commerce\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

final readonly class ClaimDeliveryTask
{
    public function __construct(private TenantContext $tenant) {}

    public function execute(string $taskId): ?DeliveryTask
    {
        $tenantId = (int) $this->tenant->requireId();

        return DB::transaction(function () use ($tenantId, $taskId): ?DeliveryTask {
            $candidate = DeliveryTask::query()->where('tenant_id', $tenantId)->whereKey($taskId)->firstOrFail();
            Order::query()->where('tenant_id', $tenantId)->whereKey($candidate->order_id)->lockForUpdate()->firstOrFail();
            $task = DeliveryTask::query()->where('tenant_id', $tenantId)->whereKey($taskId)->lockForUpdate()->firstOrFail();
            if (! in_array($task->status, [DeliveryTaskStatus::PENDING, DeliveryTaskStatus::FAILED], true)) {
                return null;
            }

            $task->update(['status' => DeliveryTaskStatus::PROCESSING, 'attempts' => $task->attempts + 1, 'failure_reason' => null]);

            return $task->refresh();
        }, attempts: 3);
    }
}
