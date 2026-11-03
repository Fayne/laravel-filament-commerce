<?php

declare(strict_types=1);

namespace Fayne\Commerce\Console\Commands;

use Fayne\CMS\Models\Tenant;
use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Orders\Actions\CancelPendingOrder;
use Fayne\Commerce\Orders\Enums\OrderStatus;
use Fayne\Commerce\Orders\Models\Order;
use Illuminate\Console\Command;
use Throwable;

final class ExpirePendingOrders extends Command
{
    protected $signature = 'commerce:orders:expire-reservations {--limit=100}';

    protected $description = 'Cancel Commerce orders whose payment reservation has expired.';

    public function handle(CancelPendingOrder $cancel, TenantContext $tenantContext): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $expired = 0;
        if (config('filament-cms.tenancy.enabled', false)) {
            foreach (Tenant::query()->where('is_active', true)->orderBy('id')->get() as $tenant) {
                $expired += $tenantContext->run($tenant, fn (): int => $this->expireForTenant($cancel, $limit - $expired));
                if ($expired >= $limit) {
                    break;
                }
            }
        } else {
            $expired = $this->expireForTenant($cancel, $limit);
        }
        $this->info("Expired {$expired} Commerce order(s).");

        return self::SUCCESS;
    }

    private function expireForTenant(CancelPendingOrder $cancel, int $limit): int
    {
        $ids = Order::query()->where('status', OrderStatus::AWAITING_PAYMENT->value)->where('reservation_expires_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id');
        $expired = 0;
        foreach ($ids as $id) {
            try {
                $cancel->execute((string) $id, 'Payment reservation expired.');
                $expired++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $expired;
    }
}
