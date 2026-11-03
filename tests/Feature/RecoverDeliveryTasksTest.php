<?php

declare(strict_types=1);

use Fayne\Commerce\Fulfillment\Enums\DeliveryTaskStatus;
use Fayne\Commerce\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(TestCase::class);

test('recovery marks only stale processing delivery tasks unknown without reissuing', function (): void {
    DB::table('commerce_delivery_tasks')->insert([
        ['tenant_id' => 0, 'order_id' => (string) Str::ulid(), 'order_line_id' => 1, 'unit_number' => 1, 'handler_key' => 'host', 'config_snapshot' => '{}', 'status' => 'processing', 'idempotency_key' => 'stale', 'attempts' => 1, 'created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)],
        ['tenant_id' => 0, 'order_id' => (string) Str::ulid(), 'order_line_id' => 2, 'unit_number' => 1, 'handler_key' => 'host', 'config_snapshot' => '{}', 'status' => 'processing', 'idempotency_key' => 'fresh', 'attempts' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    Artisan::call('commerce:deliveries:recover', ['--limit' => 10]);

    expect(DB::table('commerce_delivery_tasks')->where('idempotency_key', 'stale')->value('status'))->toBe(DeliveryTaskStatus::UNKNOWN->value)
        ->and(DB::table('commerce_delivery_tasks')->where('idempotency_key', 'fresh')->value('status'))->toBe(DeliveryTaskStatus::PROCESSING->value)
        ->and(DB::table('commerce_delivery_tasks')->where('idempotency_key', 'stale')->value('attempts'))->toBe(1);
});
