<?php

declare(strict_types=1);

namespace Fayne\Commerce\Fulfillment\Contracts;

use Fayne\Commerce\Fulfillment\Models\DeliveryTask;

interface DeliveryStatusResolver
{
    /** @return array{status: 'succeeded'|'failed'|'unknown', external_reference?: string, result?: array, failure_reason?: string} */
    public function resolve(DeliveryTask $task): array;
}
