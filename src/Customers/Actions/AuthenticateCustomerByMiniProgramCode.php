<?php

declare(strict_types=1);

namespace Fayne\Commerce\Customers\Actions;

use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Customers\Contracts\MiniProgramCodeExchanger;
use Fayne\Commerce\Customers\DTO\MiniProgramIdentity;
use Fayne\Commerce\Customers\Models\Customer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AuthenticateCustomerByMiniProgramCode
{
    public function __construct(private TenantContext $tenant, private MiniProgramCodeExchanger $exchanger) {}

    public function execute(string $code): Customer
    {
        $tenantId = $this->tenant->requireId();
        if (trim($code) === '' || strlen($code) > 512) {
            throw ValidationException::withMessages(['code' => __('commerce::customers.invalid_mini_code')]);
        }

        $identity = $this->exchanger->exchange($code, $tenantId);
        if (! $identity instanceof MiniProgramIdentity || trim($identity->appId) === '' || strlen($identity->appId) > 64 || trim($identity->openId) === '' || strlen($identity->openId) > 128) {
            throw ValidationException::withMessages(['code' => __('commerce::customers.mini_unverified')]);
        }
        $lockKey = 'commerce:customer:wechat:'.$tenantId.':'.hash('sha256', $identity->appId.':'.$identity->openId);

        return Cache::lock($lockKey, 15)->block(5, fn (): Customer => DB::transaction(function () use ($identity): Customer {
            $customer = Customer::query()->withoutGlobalScope('customer_active')->where('wechat_appid', $identity->appId)->where('wechat_openid', $identity->openId)->lockForUpdate()->first();
            if ($customer) {
                if (! $customer->is_active) {
                    throw ValidationException::withMessages(['code' => __('commerce::customers.inactive')]);
                }

                return $customer;
            }

            $customer = new Customer;
            $customer->forceFill([
                'wechat_appid' => $identity->appId,
                'wechat_openid' => $identity->openId,
                'is_active' => true,
            ])->save();

            return $customer;
        }, attempts: 3));
    }
}
