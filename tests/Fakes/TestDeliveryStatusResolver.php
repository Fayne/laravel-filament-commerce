<?php

declare(strict_types=1);

namespace Fayne\Commerce\Tests\Fakes;

use Fayne\Commerce\Fulfillment\Contracts\DeliveryStatusResolver;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use RuntimeException;

final class TestDeliveryStatusResolver implements DeliveryStatusResolver
{
    public static array $response = ['status' => 'succeeded'];

    public static ?RuntimeException $exception = null;

    public static array $calls = [];

    public function resolve(DeliveryTask $task): array
    {
        self::$calls[] = $task->getKey();
        if (self::$exception) {
            throw self::$exception;
        }

        return self::$response;
    }
}
