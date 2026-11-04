<?php

declare(strict_types=1);

namespace Fayne\Commerce\Console\Commands;

use Fayne\CMS\Database\Seeders\InitializeRolesAndPermissionsSeeder;
use Fayne\CMS\Models\Tenant;
use Fayne\CMS\Support\ContentLocale;
use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Catalog\Enums\ProductType;
use Fayne\Commerce\Catalog\Models\Product;
use Fayne\Commerce\Catalog\Models\ProductVariant;
use Fayne\Commerce\Inventory\Actions\AdjustStock;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Support\InitializeCommercePermissions;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

final class InstallCommerce extends Command
{
    use ConfirmableTrait;

    protected $signature = 'ec:core:install {--force : Allow installation in production} {--demo : Create sample products, stock, and orders} {--tenant= : Target tenant ID for demo data}';

    protected $description = 'Publish Commerce configuration, run migrations, and initialize permissions.';

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $demo = $this->option('demo');
        $allowsDemo = app()->environment('local', 'testing', 'staging');
        if ($demo && ! $allowsDemo) {
            $this->error('Demo data is available only in local, testing, or staging.');

            return self::FAILURE;
        }
        if (! $demo && $allowsDemo && $this->input->isInteractive()) {
            $demo = $this->confirm('Create sample products, stock, and orders?', false);
        }
        $tenantId = $this->option('tenant');
        $tenancy = config('filament-cms.tenancy.enabled', false);
        if ($demo && $tenancy && ! $tenantId && $this->input->isInteractive()) {
            $tenantId = $this->ask('Target tenant ID');
        }
        if ($demo && $tenancy && ! $tenantId) {
            $this->error('Specify the target tenant ID with --tenant.');

            return self::FAILURE;
        }

        $status = $this->call('vendor:publish', ['--tag' => 'commerce-config']);
        if ($status !== self::SUCCESS) {
            return $status;
        }

        $status = $this->call('migrate', ['--force' => true]);
        if ($status !== self::SUCCESS) {
            return $status;
        }

        $status = $this->call('db:seed', [
            '--class' => InitializeRolesAndPermissionsSeeder::class,
            '--force' => true,
        ]);
        if ($status === self::SUCCESS) {
            app(InitializeCommercePermissions::class)->execute();
            if ($this->getApplication()->has('filament:clear-cached-components')) {
                $status = $this->call('filament:clear-cached-components');
            }
        }
        if ($status !== self::SUCCESS || ! $demo) {
            return $status;
        }

        if ($tenancy) {
            $tenant = Tenant::query()->where('is_active', true)->find($tenantId);
            if (! $tenant) {
                $this->error('Active target tenant not found.');

                return self::FAILURE;
            }
            app(TenantContext::class)->run($tenant, $this->seedDemo(...));
        } else {
            $this->seedDemo();
        }
        $this->info('Commerce demo data is ready.');

        return self::SUCCESS;
    }

    private function seedDemo(): void
    {
        DB::transaction(function (): void {
            foreach ([
                ['EC-DEMO-NOTEBOOK', '演示笔记本', 'Demo Notebook', 2900],
                ['EC-DEMO-TOTE', '演示帆布袋', 'Demo Tote Bag', 3900],
            ] as [$sku, $chinese, $english, $amount]) {
                if (ProductVariant::query()->where('sku', $sku)->exists()) {
                    continue;
                }
                $product = Product::query()->create(['type' => 'physical', 'status' => 'active']);
                $locale = ContentLocale::default();
                $product->translations()->create([
                    'locale' => $locale,
                    'name' => str_starts_with(strtolower($locale), 'zh') ? $chinese : $english,
                ]);
                $variant = $product->variants()->create(['sku' => $sku, 'price_amount' => $amount, 'is_active' => true]);
                app(AdjustStock::class)->execute($variant, 20, 'Demo catalog');
            }
            $variant = ProductVariant::query()->where('sku', 'EC-DEMO-NOTEBOOK')->firstOrFail();
            if ($variant->product?->type !== ProductType::PHYSICAL) {
                return;
            }

            foreach (['cancelled', 'unshipped', 'partially-shipped', 'shipped'] as $scenario) {
                $this->seedDemoOrder($variant, $scenario);
            }
        });
    }

    private function seedDemoOrder(ProductVariant $variant, string $scenario): void
    {
        $key = 'ec-demo-order-'.$scenario;
        $paid = $scenario !== 'cancelled';
        $total = $variant->price_amount * 2;
        $order = Order::query()->firstOrCreate(['idempotency_key' => $key], [
            'status' => $paid ? 'confirmed' : 'cancelled',
            'payment_status' => $paid ? 'paid' : 'pending',
            'paid_at' => $paid ? now() : null,
            'cancellation_reason' => $paid ? null : 'Demo cancellation',
            'currency' => $variant->currency,
            'subtotal_amount' => $total,
            'shipping_amount' => 0,
            'discount_amount' => 0,
            'payable_amount' => $total,
            'request_fingerprint' => hash('sha256', $key),
            'buyer_snapshot' => ['name' => 'Demo Customer', 'email' => 'commerce@example.test'],
            'shipping_snapshot' => ['recipient' => 'Demo Customer', 'address' => 'Demo address'],
            'promotion_snapshot' => [],
            'locale' => ContentLocale::default(),
            'reservation_expires_at' => now(),
        ]);
        if (! $order->wasRecentlyCreated) {
            return;
        }

        $line = $order->lines()->create([
            'variant_id' => $variant->id, 'sku' => $variant->sku, 'product_type' => 'physical',
            'product_name' => $variant->getName(), 'quantity' => 2, 'unit_amount' => $variant->price_amount,
            'line_amount' => $total, 'discount_amount' => 0, 'payable_amount' => $total, 'discount_units' => [],
        ]);

        if (in_array($scenario, ['partially-shipped', 'shipped'], true)) {
            $shipment = $order->shipments()->create([
                'carrier' => 'Demo carrier', 'tracking_number' => strtoupper($key), 'status' => 'shipped',
                'idempotency_key' => $key, 'request_fingerprint' => hash('sha256', $key), 'shipped_at' => now(),
            ]);
            $shipment->lines()->create(['order_line_id' => $line->id, 'quantity' => $scenario === 'shipped' ? 2 : 1]);
        }
    }
}
