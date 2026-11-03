<?php

declare(strict_types=1);

namespace Fayne\Commerce\Customers\Actions;

use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class BindPhoneToCustomer
{
    public function __construct(private TenantContext $tenant, private ConsumePhoneVerificationCode $consumeCode) {}

    public function execute(Customer $customer, string $phone, string $code): Customer
    {
        $tenantId = $this->tenant->requireId();
        if ((string) $customer->tenant_id !== (string) $tenantId) {
            throw ValidationException::withMessages(['phone' => __('commerce::customers.current_tenant')]);
        }
        $phone = $this->consumeCode->execute($phone, $code);

        return DB::transaction(function () use ($customer, $phone): Customer {
            $record = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            if (! $record->is_active) {
                throw ValidationException::withMessages(['phone' => __('commerce::customers.inactive')]);
            }
            $owner = Customer::query()->withoutGlobalScope('customer_active')->where('phone', $phone)->lockForUpdate()->first();
            if ($owner && (string) $owner->getKey() !== (string) $record->getKey()) {
                throw ValidationException::withMessages(['phone' => __('commerce::customers.phone_owned')]);
            }
            if (filled($record->phone) && $record->phone !== $phone) {
                throw ValidationException::withMessages(['phone' => __('commerce::customers.phone_linked')]);
            }

            $record->forceFill(['phone' => $phone, 'phone_verified_at' => now()])->save();

            return $record->refresh();
        }, attempts: 3);
    }
}
