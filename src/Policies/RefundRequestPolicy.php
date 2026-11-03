<?php

declare(strict_types=1);

namespace Fayne\Commerce\Policies;

use Fayne\CMS\Models\Admin;
use Fayne\CMS\Support\Authorization\Authorization;
use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Returns\Models\RefundRequest;

final class RefundRequestPolicy
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function before(Admin $admin, string $ability, mixed $record = null): ?bool
    {
        if ($record instanceof RefundRequest && ($this->tenant->id() === null || (string) $record->tenant_id !== (string) $this->tenant->id())) {
            return false;
        }

        return null;
    }

    public function viewAny(Admin $admin): bool
    {
        return $this->allows($admin, 'tenant.viewAnyRefundRequest');
    }

    public function view(Admin $admin, RefundRequest $refundRequest): bool
    {
        return $this->allows($admin, 'tenant.viewRefundRequest');
    }

    public function review(Admin $admin, RefundRequest $refundRequest): bool
    {
        return $this->allows($admin, 'tenant.reviewRefund');
    }

    private function allows(Admin $admin, string $permission): bool
    {
        return $admin->is_active && Authorization::allows($admin, $permission);
    }
}
