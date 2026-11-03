<?php

declare(strict_types=1);

namespace Fayne\Commerce\Policies;

use Fayne\CMS\Models\Admin;
use Fayne\CMS\Support\Authorization\Authorization;
use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Orders\Models\Order;

final class OrderPolicy
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function before(Admin $admin, string $ability, mixed $record = null): ?bool
    {
        if ($record instanceof Order && ($this->tenant->id() === null || (string) $record->tenant_id !== (string) $this->tenant->id())) {
            return false;
        }

        return null;
    }

    public function viewAny(Admin $admin): bool
    {
        return $this->allows($admin, 'tenant.viewAnyOrder');
    }

    public function view(Admin $admin, Order $order): bool
    {
        return $this->allows($admin, 'tenant.viewOrder');
    }

    public function cancel(Admin $admin, Order $order): bool
    {
        return $this->allows($admin, 'tenant.cancelOrder');
    }

    public function ship(Admin $admin, Order $order): bool
    {
        return $this->allows($admin, 'tenant.shipOrder');
    }

    public function requestRefund(Admin $admin, Order $order): bool
    {
        return $this->allows($admin, 'tenant.requestRefund');
    }

    public function revokeDelivery(Admin $admin, Order $order): bool
    {
        return $this->allows($admin, 'tenant.revokeDelivery');
    }

    private function allows(Admin $admin, string $permission): bool
    {
        return $admin->is_active && Authorization::allows($admin, $permission);
    }
}
