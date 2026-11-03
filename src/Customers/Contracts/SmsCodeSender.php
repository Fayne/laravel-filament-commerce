<?php

declare(strict_types=1);

namespace Fayne\Commerce\Customers\Contracts;

interface SmsCodeSender
{
    public function send(string $phone, string $code, string|int $tenantId): void;
}
