<?php

declare(strict_types=1);

namespace Fayne\Commerce\Policies;

use Fayne\CMS\Models\Admin;
use Fayne\CMS\Support\Authorization\Authorization;
use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Catalog\Models\Product;

final class ProductPolicy
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function before(Admin $admin, string $ability, mixed $record = null): ?bool
    {
        if ($record instanceof Product && ($this->tenant->id() === null || (string) $record->tenant_id !== (string) $this->tenant->id())) {
            return false;
        }

        return null;
    }

    public function viewAny(Admin $admin): bool
    {
        return $this->allows($admin, 'tenant.viewAnyProduct');
    }

    public function view(Admin $admin, Product $product): bool
    {
        return $this->allows($admin, 'tenant.viewProduct');
    }

    public function create(Admin $admin): bool
    {
        return $this->allows($admin, 'tenant.createProduct');
    }

    public function update(Admin $admin, Product $product): bool
    {
        return $this->allows($admin, 'tenant.updateProduct');
    }

    public function delete(Admin $admin, Product $product): bool
    {
        return $this->allows($admin, 'tenant.deleteProduct');
    }

    public function deleteAny(Admin $admin): bool
    {
        return $this->allows($admin, 'tenant.deleteProduct');
    }

    private function allows(Admin $admin, string $permission): bool
    {
        return $admin->is_active && Authorization::allows($admin, $permission);
    }
}
