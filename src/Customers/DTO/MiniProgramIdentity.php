<?php

declare(strict_types=1);

namespace Fayne\Commerce\Customers\DTO;

final readonly class MiniProgramIdentity
{
    public function __construct(public string $appId, public string $openId) {}
}
