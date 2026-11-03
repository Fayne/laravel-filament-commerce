<?php

declare(strict_types=1);

namespace Fayne\Commerce\Policies;

use Fayne\CMS\Models\Admin;
use Fayne\CMS\Support\Authorization\Authorization;
use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Catalog\Models\ProductVariant;
use Fayne\Commerce\Inventory\Models\StockMovement;

final class InventoryPolicy
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function before(Admin $admin, string $ability, mixed $record = null): ?bool
    {
        if (($record instanceof ProductVariant || $record instanceof StockMovement) && ($this->tenant->id() === null || (string) $record->tenant_id !== (string) $this->tenant->id())) {
            return false;
        }

        return null;
    }

    public function viewAnyStock(Admin $admin): bool
    {
        return $this->allows($admin, 'tenant.viewAnyStock');
    }

    public function viewAny(Admin $admin): bool
    {
        return $this->viewAnyStock($admin);
    }

    public function view(Admin $admin, ProductVariant $variant): bool
    {
        return $this->viewStock($admin, $variant);
    }

    public function viewStock(Admin $admin, ProductVariant $variant): bool
    {
        return $this->allows($admin, 'tenant.viewStock');
    }

    public function adjustStock(Admin $admin, ProductVariant $variant): bool
    {
        return $this->allows($admin, 'tenant.adjustStock');
    }

    public function viewAnyStockMovement(Admin $admin): bool
    {
        return $this->allows($admin, 'tenant.viewAnyStockMovement');
    }

    public function viewStockMovement(Admin $admin, StockMovement $movement): bool
    {
        return $this->allows($admin, 'tenant.viewStockMovement');
    }

    private function allows(Admin $admin, string $permission): bool
    {
        return $admin->is_active && Authorization::allows($admin, $permission);
    }
}
