<?php

declare(strict_types=1);

namespace Fayne\Commerce\Cart;

use Fayne\ShoppingCart\Contracts\CartSkuQuery;
use Illuminate\Database\Eloquent\Builder;

final class CommerceSkuQuery implements CartSkuQuery
{
    public function forTenant(string $model, int|string $tenantId): Builder
    {
        $query = $model::query();

        return $query->where($query->qualifyColumn('tenant_id'), $tenantId);
    }
}
