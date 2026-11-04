<?php

declare(strict_types=1);

use Fayne\Commerce\Cart\CommerceCartContextResolver;
use Fayne\Commerce\Cart\CommerceSkuQuery;
use Fayne\Commerce\Catalog\Models\ProductVariant;
use Fayne\Commerce\Checkout\Actions\CheckoutCart;
use Fayne\Commerce\Console\Commands\ExpirePendingOrders;
use Fayne\Commerce\Console\Commands\ExpireStockReservations;
use Fayne\Commerce\Console\Commands\PublishCommerceOutbox;
use Fayne\Commerce\Console\Commands\RecoverDeliveryTasks;
use Fayne\Commerce\Orders\Actions\ConfirmOrderPayment as ConfirmOrderPaymentAction;
use Fayne\Commerce\Tenancy\Actions\TenantCurrency;

return [
    'extensions' => ['products' => [], 'skus' => []],
    'cart' => [
        'sku_model' => ProductVariant::class,
        'sku_query' => CommerceSkuQuery::class,
        'currency_resolver' => TenantCurrency::class,
        'context_resolver' => CommerceCartContextResolver::class,
    ],
    'actions' => [
        'payment_confirmation' => ConfirmOrderPaymentAction::class,
        'cart_checkout' => CheckoutCart::class,
    ],
    'customers' => [
        'guard' => 'customer',
        'sms_sender' => null,
        'mini_program_code_exchanger' => null,
    ],
    'fulfillment' => [
        'handlers' => [],
        'status_resolvers' => [],
        'revokers' => [],
        'revocation_status_resolvers' => [],
    ],
    'routes' => [
        'customer_delivery' => [
            'enabled' => true,
            'prefix' => 'api/commerce/customer',
            'middleware' => ['api', 'auth:customer'],
        ],
    ],
    'operations' => [
        'commands' => [
            'publish_outbox' => PublishCommerceOutbox::class,
            'expire_orders' => ExpirePendingOrders::class,
            'expire_reservations' => ExpireStockReservations::class,
            'recover_deliveries' => RecoverDeliveryTasks::class,
        ],
        'schedule' => [
            'enabled' => false,
            'publish_outbox' => 'everyMinute',
            'expire_orders' => 'everyMinute',
            'expire_reservations' => 'everyFiveMinutes',
            'recover_deliveries' => 'everyFiveMinutes',
            'stale_delivery_minutes' => 10,
        ],
    ],
];
