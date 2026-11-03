<?php

declare(strict_types=1);

namespace Fayne\Commerce\Customers\Actions;

use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Customers\Contracts\MiniProgramCodeExchanger;
use Fayne\Commerce\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class BindMiniProgramToCustomer
{
    public function __construct(private TenantContext $tenant, private MiniProgramCodeExchanger $exchanger) {}

    public function execute(Customer $customer, string $code): Customer
    {
        $tenantId = $this->tenant->requireId();
        if ((string) $customer->tenant_id !== (string) $tenantId || trim($code) === '' || strlen($code) > 512) {
            throw ValidationException::withMessages(['wechat' => __('commerce::customers.current_tenant')]);
        }
        $identity = $this->exchanger->exchange($code, $tenantId);
        if (trim($identity->appId) === '' || strlen($identity->appId) > 64 || trim($identity->openId) === '' || strlen($identity->openId) > 128) {
            throw ValidationException::withMessages(['wechat' => __('commerce::customers.mini_unverified')]);
        }

        return DB::transaction(function () use ($customer, $identity): Customer {
            $record = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            if (! $record->is_active) {
                throw ValidationException::withMessages(['wechat' => __('commerce::customers.inactive')]);
            }
            $owner = Customer::query()->withoutGlobalScope('customer_active')->where('wechat_appid', $identity->appId)->where('wechat_openid', $identity->openId)->lockForUpdate()->first();
            if ($owner && (string) $owner->getKey() !== (string) $record->getKey()) {
                throw ValidationException::withMessages(['wechat' => __('commerce::customers.wechat_owned')]);
            }
            if (filled($record->wechat_openid) && ($record->wechat_appid !== $identity->appId || $record->wechat_openid !== $identity->openId)) {
                throw ValidationException::withMessages(['wechat' => __('commerce::customers.wechat_linked')]);
            }

            $record->forceFill(['wechat_appid' => $identity->appId, 'wechat_openid' => $identity->openId])->save();

            return $record->refresh();
        }, attempts: 3);
    }
}
