<?php

declare(strict_types=1);

namespace Fayne\Commerce\Fulfillment\Contracts;

use Fayne\Commerce\Fulfillment\Models\DeliveryTask;

interface DeliveryRevocationStatusResolver
{
    public function resolve(DeliveryTask $task, string $idempotencyKey): string;
}
