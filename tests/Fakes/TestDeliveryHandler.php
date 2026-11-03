<?php

declare(strict_types=1);

namespace Fayne\Commerce\Tests\Fakes;

use Fayne\Commerce\Fulfillment\Contracts\DeliveryHandler;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use RuntimeException;

final class TestDeliveryHandler implements DeliveryHandler
{
    public static array $response = ['status' => 'succeeded'];

    public static ?RuntimeException $exception = null;

    public static array $calls = [];

    public function deliver(DeliveryTask $task, string $idempotencyKey): array
    {
        self::$calls[] = ['task_id' => $task->getKey(), 'idempotency_key' => $idempotencyKey];
        if (self::$exception) {
            throw self::$exception;
        }

        return self::$response;
    }
}
