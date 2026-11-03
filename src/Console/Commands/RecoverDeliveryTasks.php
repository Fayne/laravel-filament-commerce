<?php

declare(strict_types=1);

namespace Fayne\Commerce\Console\Commands;

use Fayne\CMS\Models\Tenant;
use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Fulfillment\Enums\DeliveryTaskStatus;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use Illuminate\Console\Command;

final class RecoverDeliveryTasks extends Command
{
    protected $signature = 'commerce:deliveries:recover {--limit=100}';

    protected $description = 'Mark stale processing delivery tasks as unknown.';

    public function handle(TenantContext $tenantContext): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $recovered = 0;
        $minutes = max(1, (int) config('commerce.operations.schedule.stale_delivery_minutes', 10));
        if (config('filament-cms.tenancy.enabled', false)) {
            foreach (Tenant::query()->where('is_active', true)->orderBy('id')->get() as $tenant) {
                $recovered += $tenantContext->run($tenant, fn (): int => $this->recoverForTenant($limit - $recovered, $minutes));
                if ($recovered >= $limit) {
                    break;
                }
            }
        } else {
            $recovered = $this->recoverForTenant($limit, $minutes);
        }
        $this->info("Marked {$recovered} stale delivery task(s) unknown.");

        return self::SUCCESS;
    }

    private function recoverForTenant(int $limit, int $minutes): int
    {
        $recovered = DeliveryTask::query()->where('status', DeliveryTaskStatus::PROCESSING)
            ->where('updated_at', '<=', now()->subMinutes($minutes))->orderBy('id')->limit($limit)
            ->update(['status' => DeliveryTaskStatus::UNKNOWN, 'updated_at' => now()]);

        if ($recovered < $limit) {
            $recovered += DeliveryTask::query()->where('revocation_status', 'processing')
                ->where('updated_at', '<=', now()->subMinutes($minutes))->orderBy('id')->limit($limit - $recovered)
                ->update(['revocation_status' => 'unknown']);
        }

        return $recovered;
    }
}
