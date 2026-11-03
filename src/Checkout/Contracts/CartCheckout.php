<?php

declare(strict_types=1);

namespace Fayne\Commerce\Checkout\Contracts;

use Fayne\Commerce\Checkout\DTO\CartCheckoutData;
use Fayne\Commerce\Orders\Models\Order;

interface CartCheckout
{
    public function execute(CartCheckoutData $data): Order;
}
