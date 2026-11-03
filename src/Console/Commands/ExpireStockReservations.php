<?php

declare(strict_types=1);

namespace Fayne\Commerce\Console\Commands;

use Fayne\CMS\Models\Tenant;
use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Inventory\Actions\ReleaseStockReservation;
use Fayne\Commerce\Inventory\Enums\StockReservationStatus;
use Fayne\Commerce\Inventory\Models\StockReservation;
use Illuminate\Console\Command;
use Throwable;

final class ExpireStockReservations extends Command
{
    protected $signature = 'commerce:stock:expire-reservations {--limit=100}';

    protected $description = 'Release expired Commerce stock reservations.';

    public function handle(TenantContext $tenantContext): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $expired = 0;
        if (config('filament-cms.tenancy.enabled', false)) {
            foreach (Tenant::query()->where('is_active', true)->orderBy('id')->get() as $tenant) {
                $expired += $tenantContext->run($tenant, fn (): int => $this->expireForTenant($limit - $expired));
                if ($expired >= $limit) {
                    break;
                }
            }
        } else {
            $expired = $this->expireForTenant($limit);
        }
        $this->info("Released {$expired} expired stock reservation(s).");

        return self::SUCCESS;
    }

    private function expireForTenant(int $limit): int
    {
        $ids = StockReservation::query()->where('status', StockReservationStatus::RESERVED)
            ->where('source_type', '!=', 'commerce_order')
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id');
        $released = 0;
        foreach ($ids as $id) {
            try {
                app(ReleaseStockReservation::class)->execute((int) $id);
                $released++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $released;
    }
}
