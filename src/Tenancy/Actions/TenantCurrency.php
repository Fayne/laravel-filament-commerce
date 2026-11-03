<?php

declare(strict_types=1);

namespace Fayne\Commerce\Tenancy\Actions;

use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Catalog\Models\ProductVariant;
use Fayne\Commerce\Tenancy\Models\TenantCommerceSetting;
use Fayne\ShoppingCart\Contracts\CartCurrencyResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TenantCurrency implements CartCurrencyResolver
{
    public function current(): string
    {
        $tenantId = app(TenantContext::class)->requireId();

        return TenantCommerceSetting::query()->where('tenant_id', $tenantId)->value('currency') ?? 'CNY';
    }

    public function set(string $currency): void
    {
        $tenantId = app(TenantContext::class)->requireId();
        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw ValidationException::withMessages(['currency' => 'Currency must be a three-letter ISO code.']);
        }

        DB::transaction(function () use ($tenantId, $currency): void {
            $setting = TenantCommerceSetting::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();
            if ($setting && $setting->currency !== $currency && ProductVariant::query()->where('tenant_id', $tenantId)->exists()) {
                throw ValidationException::withMessages(['currency' => 'Tenant currency cannot change after SKU prices exist.']);
            }

            TenantCommerceSetting::query()->updateOrCreate(['tenant_id' => $tenantId], ['currency' => $currency]);
        });
    }
}
