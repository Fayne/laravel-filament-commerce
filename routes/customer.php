<?php

declare(strict_types=1);

use Fayne\Commerce\Http\Controllers\CustomerDeliveryController;
use Illuminate\Support\Facades\Route;

Route::middleware(config('commerce.routes.customer_delivery.middleware', ['api', 'auth:customer']))
    ->prefix(trim(config('commerce.routes.customer_delivery.prefix', 'api/commerce/customer'), '/'))
    ->name('commerce.customer.')
    ->group(function (): void {
        Route::get('deliveries', [CustomerDeliveryController::class, 'index'])->name('deliveries.index');
    });
