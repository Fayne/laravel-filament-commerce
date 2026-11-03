<?php

declare(strict_types=1);

namespace Fayne\Commerce\Fulfillment\Actions;

use Fayne\Commerce\Catalog\Enums\ProductType;
use Fayne\Commerce\Fulfillment\Enums\DeliveryTaskStatus;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Orders\Models\OrderLine;
use Fayne\Commerce\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateDeliveryTasks
{
    public function execute(string $orderId): int
    {
        return DB::transaction(function () use ($orderId): int {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'confirmed' || ! in_array($order->payment_status, ['paid', 'not_required'], true)) {
                throw ValidationException::withMessages(['order' => 'Only a paid or zero-value confirmed order can be delivered.']);
            }

            $created = 0;
            $lines = OrderLine::query()->where('order_id', $order->getKey())->orderBy('id')->lockForUpdate()->get();
            foreach ($lines as $line) {
                if (! in_array($line->product_type, [ProductType::DIGITAL->value, ProductType::SERVICE->value], true)) {
                    continue;
                }
                $handlerKey = $line->delivery_snapshot['handler'] ?? null;
                if (! is_string($handlerKey) || $handlerKey === '') {
                    throw ValidationException::withMessages(['delivery' => 'A delivery handler key is missing from the order snapshot.']);
                }

                for ($unit = 1; $unit <= $line->quantity; $unit++) {
                    $key = 'commerce-delivery:'.$line->getKey().':'.$unit;
                    $task = DeliveryTask::query()->firstOrCreate([
                        'tenant_id' => $order->tenant_id,
                        'order_line_id' => $line->getKey(),
                        'unit_number' => $unit,
                    ], [
                        'order_id' => $order->getKey(),
                        'handler_key' => $handlerKey,
                        'config_snapshot' => $line->delivery_snapshot['config'] ?? [],
                        'status' => DeliveryTaskStatus::PENDING,
                        'idempotency_key' => $key,
                    ]);
                    if ($task->wasRecentlyCreated) {
                        $created++;
                        OutboxMessage::query()->create([
                            'event_key' => 'delivery.requested:'.$task->getKey(),
                            'event_type' => 'commerce.delivery.requested',
                            'payload' => ['order_id' => $order->getKey(), 'delivery_task_id' => $task->getKey()],
                            'available_at' => now(),
                        ]);
                    }
                }
            }

            return $created;
        }, attempts: 3);
    }
}
