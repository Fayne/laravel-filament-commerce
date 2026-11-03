<?php

declare(strict_types=1);

namespace Fayne\Commerce\Fulfillment\Contracts;

use Fayne\Commerce\Fulfillment\Models\DeliveryTask;

interface DeliveryRevoker
{
    /** @return array{status: 'succeeded'|'failed'|'unknown', result?: array, failure_reason?: string} */
    public function revoke(DeliveryTask $task, string $idempotencyKey): array;
}
