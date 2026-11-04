<?php

use Fayne\Commerce\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

uses(TestCase::class);

test('commerce installs rolls back and reinstalls from its baseline migrations', function (): void {
    Schema::dropIfExists('commerce_delivery_tasks');
    Schema::dropIfExists('commerce_outbox_messages');
    Schema::dropIfExists('commerce_orders');
    $files = glob(__DIR__.'/../../database/migrations/*.php');
    sort($files);

    for ($attempt = 0; $attempt < 2; $attempt++) {
        foreach ($files as $file) {
            (require $file)->up();
        }
        expect(Schema::hasColumns('commerce_orders', ['payment_status', 'paid_at', 'cancellation_reason']))->toBeTrue()
            ->and(Schema::hasColumns('commerce_customers', ['wechat_appid', 'wechat_openid']))->toBeTrue()
            ->and(Schema::hasColumns('commerce_delivery_tasks', ['revocation_status', 'revocation_attempt']))->toBeTrue()
            ->and(Schema::hasTable('commerce_customer_phone_codes'))->toBeTrue()
            ->and(Schema::hasColumn('commerce_product_variants', 'extension_data'))->toBeTrue()
            ->and(Schema::hasTable('commerce_product_extensions'))->toBeTrue();
        foreach (array_reverse($files) as $file) {
            (require $file)->down();
        }
        expect(Schema::hasTable('commerce_orders'))->toBeFalse()
            ->and(Schema::hasTable('commerce_customers'))->toBeFalse();
    }
});
