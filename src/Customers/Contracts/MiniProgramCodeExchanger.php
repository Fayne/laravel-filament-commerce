<?php

declare(strict_types=1);

namespace Fayne\Commerce\Customers\Contracts;

use Fayne\Commerce\Customers\DTO\MiniProgramIdentity;

interface MiniProgramCodeExchanger
{
    public function exchange(string $code, string|int $tenantId): MiniProgramIdentity;
}
