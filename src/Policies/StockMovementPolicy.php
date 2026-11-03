<?php

declare(strict_types=1);

namespace Fayne\Commerce\Policies;

use Fayne\CMS\Models\Admin;
use Fayne\CMS\Support\Authorization\Authorization;
use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Inventory\Models\StockMovement;

final class StockMovementPolicy
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function before(Admin $admin, string $ability, mixed $record = null): ?bool
    {
        if ($record instanceof StockMovement && ($this->tenant->id() === null || (string) $record->tenant_id !== (string) $this->tenant->id())) {
            return false;
        }

        return null;
    }

    public function viewAny(Admin $admin): bool
    {
        return $admin->is_active && Authorization::allows($admin, 'tenant.viewAnyStockMovement');
    }

    public function view(Admin $admin, StockMovement $movement): bool
    {
        return $admin->is_active && Authorization::allows($admin, 'tenant.viewStockMovement');
    }
}
