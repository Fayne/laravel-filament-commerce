<?php

declare(strict_types=1);

use Fayne\Commerce\Catalog\Enums\ProductType;
use Fayne\Commerce\Catalog\Models\Product;
use Fayne\Commerce\Catalog\Models\ProductTranslation;
use Fayne\Commerce\Catalog\Models\ProductVariant;
use Fayne\Commerce\Checkout\Actions\CheckoutCart;
use Fayne\Commerce\Checkout\DTO\CartCheckoutData;
use Fayne\Commerce\Customers\Models\Customer;
use Fayne\Commerce\Inventory\Models\StockLevel;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Tests\TestCase;
use Fayne\ShoppingCart\ShoppingCart;
use Fayne\ShoppingCart\ShoppingCartServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommerceCartIntegrationTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ShoppingCartServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('shopping-cart.routes.enabled', false);
        $app['config']->set('shopping-cart.models.users.enforce_cart_foreign_key', false);
        $app['config']->set('auth.guards.customer', ['driver' => 'session', 'provider' => 'commerce_customers']);
        $app['config']->set('auth.providers.commerce_customers', ['driver' => 'eloquent', 'model' => Customer::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('commerce_outbox_messages');
        Schema::dropIfExists('commerce_delivery_tasks');
        Schema::dropIfExists('commerce_orders');

        $root = dirname(__DIR__, 2);
        $migrations = [
            $root.'/database/migrations/2026_11_02_000001_create_commerce_catalog_tables.php',
            dirname($root).'/laravel-filament-promotion/database/migrations/2026_11_02_000001_create_promotions_table.php',
            dirname($root).'/laravel-filament-promotion/database/migrations/2026_11_02_000002_create_coupons_table.php',
            dirname($root).'/laravel-filament-promotion/database/migrations/2026_11_02_000003_create_coupon_reservations_table.php',
            $root.'/database/migrations/2026_11_02_000002_create_commerce_inventory_tables.php',
            $root.'/database/migrations/2026_11_02_000003_create_commerce_order_tables.php',
            $root.'/database/migrations/2026_11_02_000004_add_commerce_order_payment_state.php',
            $root.'/database/migrations/2026_11_02_000005_add_commerce_order_cancellation_reason.php',
            $root.'/database/migrations/2026_11_02_000006_create_commerce_shipments.php',
            $root.'/database/migrations/2026_11_02_000007_create_commerce_refund_tables.php',
            $root.'/database/migrations/2026_11_02_000008_create_commerce_delivery_tasks.php',
            $root.'/database/migrations/2026_11_02_000009_change_encrypted_commerce_columns_to_text.php',
            $root.'/database/migrations/2026_11_02_000010_create_commerce_customers.php',
        ];
        foreach ($migrations as $migration) {
            (require $migration)->up();
        }
        foreach (glob(dirname($root).'/laravel-shopping-cart/database/migrations/*.php') as $migration) {
            (require $migration)->up();
        }
    }
}

uses(CommerceCartIntegrationTestCase::class);

function commerceCartFixture(): ProductVariant
{
    $product = Product::query()->create(['type' => ProductType::PHYSICAL->value, 'status' => 'active']);
    ProductTranslation::query()->create(['product_id' => $product->getKey(), 'locale' => 'en', 'name' => 'Widget']);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->getKey(), 'sku' => 'WIDGET-01', 'price_amount' => 250, 'is_active' => true,
    ]);
    StockLevel::query()->create(['variant_id' => $variant->getKey(), 'on_hand' => 10, 'reserved' => 0]);

    return $variant;
}

function authenticateCommerceCartCustomer(): void
{
    $customerId = DB::table('commerce_customers')->insertGetId([
        'tenant_id' => 0, 'name' => 'Buyer', 'phone' => '+8613800000000', 'password' => bcrypt('unused'),
    ]);
    Auth::guard('customer')->setUser(Customer::query()->findOrFail($customerId));
}

test('re-quotes configured Cartable SKU values and consumes the cart in the order transaction', function (): void {
    authenticateCommerceCartCustomer();
    $variant = commerceCartFixture();
    $cart = app(ShoppingCart::class);
    $cart->addProduct($variant, 2);
    $activeCartId = $cart->getCart()->getKey();
    expect(DB::table('commerce_products')->value('type'))->toBe(ProductType::PHYSICAL->value)
        ->and($variant->product()->value('type'))->toBe(ProductType::PHYSICAL);
    $variant->update(['price_amount' => 350]);

    $order = app(CheckoutCart::class)->execute(new CartCheckoutData(
        buyer: ['name' => 'Buyer', 'phone' => '+8613800000000'],
        shippingAddress: ['recipient' => 'Buyer', 'address' => 'Warehouse 1'],
        shippingAmount: 0,
        customerId: 1,
        locale: 'en',
        idempotencyKey: (string) Str::uuid(),
        reservationExpiresAt: now()->addMinutes(15),
    ));

    expect($order->lines->sole()->unit_amount)->toBe(350)
        ->and($order->payable_amount)->toBe(700)
        ->and(DB::table('shopping_cart_items')->where('cart_id', $activeCartId)->count())->toBe(0)
        ->and(Order::query()->count())->toBe(1);
});

test('rolls back order creation and keeps cart lines when order placement fails', function (): void {
    authenticateCommerceCartCustomer();
    $cart = app(ShoppingCart::class);
    $cart->addProduct(commerceCartFixture(), 1);
    $activeCartId = $cart->getCart()->getKey();

    expect(fn () => app(CheckoutCart::class)->execute(new CartCheckoutData(
        buyer: ['name' => '', 'phone' => '+8613800000000'],
        shippingAddress: ['recipient' => 'Buyer', 'address' => 'Warehouse 1'],
        shippingAmount: 0,
        customerId: 1,
        locale: 'en',
        idempotencyKey: (string) Str::uuid(),
        reservationExpiresAt: now()->addMinutes(15),
    )))->toThrow(ValidationException::class);

    expect(Order::query()->count())->toBe(0)
        ->and(DB::table('shopping_cart_items')->where('cart_id', $activeCartId)->count())->toBe(1);
});
