<?php

declare(strict_types=1);

use Fayne\CMS\Support\Authorization\PermissionRegistry;
use Fayne\Commerce\Catalog\Models\Product;
use Fayne\Commerce\Catalog\Models\ProductVariant;
use Fayne\Commerce\CommercePlugin;
use Fayne\Commerce\Console\Commands\ExpireStockReservations;
use Fayne\Commerce\Filament\Resources\StockLevelResource;
use Fayne\Commerce\Filament\Resources\StockMovementResource;
use Fayne\Commerce\Inventory\Enums\StockReservationStatus;
use Fayne\Commerce\Inventory\Models\StockLevel;
use Fayne\Commerce\Inventory\Models\StockMovement;
use Fayne\Commerce\Inventory\Models\StockReservation;
use Fayne\Commerce\Policies\InventoryPolicy;
use Fayne\Commerce\Policies\StockMovementPolicy;
use Fayne\Commerce\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(TestCase::class);

test('registers stock management resources and tenant permissions', function (): void {
    expect(CommercePlugin::make()->getId())->toBe('commerce')
        ->and(config('commerce.operations.commands.expire_reservations'))->toBe(ExpireStockReservations::class)
        ->and(Gate::getPolicyFor(ProductVariant::class))->toBeInstanceOf(InventoryPolicy::class)
        ->and(Gate::getPolicyFor(StockMovement::class))->toBeInstanceOf(StockMovementPolicy::class)
        ->and(app(PermissionRegistry::class)->tenantPermissions('all'))->toContain('tenant.viewAnyStock', 'tenant.adjustStock', 'tenant.viewAnyStockMovement');
});

test('stock resources are authorized through inventory policy', function (): void {
    expect(StockLevelResource::getModel())->toBe(ProductVariant::class)
        ->and(StockMovementResource::getModel())->toBe(StockMovement::class)
        ->and(StockLevelResource::getPages())->toHaveKey('index')
        ->and(StockMovementResource::getPages())->toHaveKey('index');
});

test('expires only overdue active reservations and releases reserved stock', function (): void {
    createInventorySchemaForTest();
    Schema::create('commerce_tenant_settings', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->unique();
        $table->char('currency', 3)->default('CNY');
        $table->timestamps();
    });
    Schema::create('commerce_products', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->default(0);
        $table->string('type')->default('physical');
        $table->string('status')->default('active');
        $table->string('delivery_handler')->nullable();
        $table->json('delivery_config')->nullable();
        $table->timestamps();
    });
    DB::table('commerce_tenant_settings')->insert(['tenant_id' => 0, 'currency' => 'CNY']);
    $product = Product::query()->create(['type' => 'physical', 'status' => 'active']);
    $variant = ProductVariant::query()->create(['product_id' => $product->id, 'sku' => 'SKU-1', 'price_amount' => 100, 'currency' => 'CNY', 'is_active' => true]);
    $level = StockLevel::query()->create(['variant_id' => $variant->id, 'on_hand' => 10, 'reserved' => 4]);
    $expired = StockReservation::query()->create(['variant_id' => $variant->id, 'source_type' => 'cart', 'source_id' => 'expired', 'quantity' => 2, 'status' => StockReservationStatus::RESERVED, 'expires_at' => now()->subMinute()]);
    StockReservation::query()->create(['variant_id' => $variant->id, 'source_type' => 'cart', 'source_id' => 'live', 'quantity' => 2, 'status' => StockReservationStatus::RESERVED, 'expires_at' => now()->addMinute()]);
    StockReservation::query()->create(['variant_id' => $variant->id, 'source_type' => 'cart', 'source_id' => 'no-expiry', 'quantity' => 0, 'status' => StockReservationStatus::RESERVED]);
    $orderReservation = StockReservation::query()->create(['variant_id' => $variant->id, 'source_type' => 'commerce_order', 'source_id' => 'order', 'quantity' => 2, 'status' => StockReservationStatus::RESERVED, 'expires_at' => now()->subMinute()]);

    $command = app(ExpireStockReservations::class);
    $method = new ReflectionMethod($command, 'expireForTenant');
    $method->setAccessible(true);
    expect($method->invoke($command, 10))->toBe(1);
    expect($expired->refresh()->status)->toBe(StockReservationStatus::RELEASED)
        ->and($orderReservation->refresh()->status)->toBe(StockReservationStatus::RESERVED)
        ->and($level->refresh()->reserved)->toBe(2)
        ->and(StockMovement::query()->where('reason', 'release')->count())->toBe(1);
});

function createInventorySchemaForTest(): void
{
    Schema::create('commerce_product_variants', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->default(0);
        $table->unsignedBigInteger('product_id');
        $table->string('sku');
        $table->unsignedBigInteger('price_amount')->default(0);
        $table->string('currency')->default('CNY');
        $table->json('option_values')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
    Schema::create('commerce_stock_levels', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->default(0);
        $table->unsignedBigInteger('variant_id');
        $table->unsignedBigInteger('on_hand')->default(0);
        $table->unsignedBigInteger('reserved')->default(0);
        $table->timestamps();
    });
    Schema::create('commerce_stock_reservations', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->default(0);
        $table->unsignedBigInteger('variant_id');
        $table->string('source_type');
        $table->string('source_id');
        $table->unsignedInteger('quantity');
        $table->string('status')->default('reserved');
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('committed_at')->nullable();
        $table->timestamp('released_at')->nullable();
        $table->timestamps();
    });
    Schema::create('commerce_stock_movements', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->default(0);
        $table->unsignedBigInteger('variant_id');
        $table->string('source_type')->nullable();
        $table->string('source_id')->nullable();
        $table->string('reason');
        $table->bigInteger('on_hand_delta');
        $table->bigInteger('reserved_delta');
        $table->timestamps();
    });
}

test('registers the customer delivery endpoint behind the configured customer guard', function (): void {
    $route = collect(app('router')->getRoutes())->first(fn ($route): bool => $route->getName() === 'commerce.customer.deliveries.index');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('api/commerce/customer/deliveries')
        ->and($route->gatherMiddleware())->toContain('auth:customer');
});
