<?php

declare(strict_types=1);

namespace Fayne\Commerce\Http\Controllers;

use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use Fayne\Commerce\Orders\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerDeliveryController
{
    public function index(Request $request): JsonResponse
    {
        $customerId = $request->user(config('commerce.customers.guard', 'customer'))->getAuthIdentifier();
        $orders = Order::query()->where('customer_id', $customerId)->with(['deliveryTasks.line'])->latest()->paginate(20);

        return response()->json($orders->through(fn (Order $order): array => [
            'order_id' => $order->getKey(),
            'deliveries' => $order->deliveryTasks->map(fn (DeliveryTask $task): array => [
                'id' => $task->getKey(),
                'sku' => $task->line?->sku,
                'product_name' => $task->line?->product_name,
                'status' => $task->revocation_status === 'succeeded' ? 'revoked' : $task->status->value,
                'result' => $task->status->value === 'succeeded' && in_array($task->revocation_status, [null, 'failed'], true) ? $task->result : null,
                'completed_at' => $task->completed_at?->toIso8601String(),
            ])->all(),
        ]));
    }
}
