<?php

declare(strict_types=1);

namespace Fayne\Commerce\Customers\Actions;

use Fayne\Commerce\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AuthenticateCustomerByPhoneCode
{
    public function __construct(private ConsumePhoneVerificationCode $consumeCode) {}

    public function execute(string $phone, string $code): Customer
    {
        $phone = $this->consumeCode->execute($phone, $code);

        return DB::transaction(function () use ($phone): Customer {
            $customer = Customer::query()->withoutGlobalScope('customer_active')->where('phone', $phone)->lockForUpdate()->first();
            if ($customer && ! $customer->is_active) {
                throw ValidationException::withMessages(['code' => __('commerce::customers.inactive')]);
            }
            if (! $customer) {
                $customer = new Customer;
                $customer->forceFill(['phone' => $phone, 'phone_verified_at' => now(), 'is_active' => true])->save();
            } elseif (! $customer->phone_verified_at) {
                $customer->forceFill(['phone_verified_at' => now()])->save();
            }

            return $customer;
        }, attempts: 3);
    }
}
