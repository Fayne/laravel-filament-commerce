<?php

declare(strict_types=1);

namespace Fayne\Commerce\Cart;

use Fayne\CMS\Support\TenantContext;
use Fayne\ShoppingCart\Contracts\CartContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final readonly class CommerceCartContextResolver implements CartContextResolver
{
    public function __construct(private TenantContext $tenant, private Request $request) {}

    public function tenantId(): int|string
    {
        return $this->tenant->requireId();
    }

    public function customerId(): int|string|null
    {
        return Auth::guard(config('commerce.customers.guard', 'customer'))->id();
    }

    public function sessionId(): ?string
    {
        return $this->request->hasSession() ? $this->request->session()->getId() : null;
    }
}
