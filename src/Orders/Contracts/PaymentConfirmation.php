<?php

declare(strict_types=1);

namespace Fayne\Commerce\Orders\Contracts;

use Fayne\Commerce\Orders\Models\Order;

interface PaymentConfirmation
{
    public function execute(string $orderId, string $provider, string $providerReference, int $amount, string $currency): Order;
}
