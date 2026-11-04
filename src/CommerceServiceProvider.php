<?php

declare(strict_types=1);

namespace Fayne\Commerce;

use Fayne\CMS\Support\Authorization\PermissionRegistry;
use Fayne\Commerce\Catalog\Models\Product;
use Fayne\Commerce\Catalog\Models\ProductVariant;
use Fayne\Commerce\Checkout\Contracts\CartCheckout;
use Fayne\Commerce\Console\Commands\InstallCommerce;
use Fayne\Commerce\Customers\Contracts\MiniProgramCodeExchanger;
use Fayne\Commerce\Customers\Contracts\SmsCodeSender;
use Fayne\Commerce\Fulfillment\DeliveryHandlerRegistry;
use Fayne\Commerce\Inventory\Models\StockMovement;
use Fayne\Commerce\Orders\Contracts\PaymentConfirmation;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Policies\InventoryPolicy;
use Fayne\Commerce\Policies\OrderPolicy;
use Fayne\Commerce\Policies\ProductPolicy;
use Fayne\Commerce\Policies\RefundRequestPolicy;
use Fayne\Commerce\Policies\StockMovementPolicy;
use Fayne\Commerce\Returns\Models\RefundRequest;
use Fayne\ShoppingCart\Contracts\CartContextResolver;
use Fayne\ShoppingCart\Contracts\CartCurrencyResolver;
use Fayne\ShoppingCart\Contracts\CartSkuQuery;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class CommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerce.php', 'commerce');
        $this->app->singleton(DeliveryHandlerRegistry::class);
        foreach ([
            CartSkuQuery::class => 'commerce.cart.sku_query',
            CartCurrencyResolver::class => 'commerce.cart.currency_resolver',
            CartContextResolver::class => 'commerce.cart.context_resolver',
            PaymentConfirmation::class => 'commerce.actions.payment_confirmation',
            CartCheckout::class => 'commerce.actions.cart_checkout',
            SmsCodeSender::class => 'commerce.customers.sms_sender',
            MiniProgramCodeExchanger::class => 'commerce.customers.mini_program_code_exchanger',
        ] as $contract => $configKey) {
            $implementation = $this->app['config']->get($configKey);
            if (is_string($implementation) && $implementation !== '') {
                $this->app->bindIf($contract, fn ($app) => $app->make($app['config']->get($configKey)));
            }
        }
        $this->app->afterResolving(DeliveryHandlerRegistry::class, function (DeliveryHandlerRegistry $registry): void {
            foreach (config('commerce.fulfillment.handlers', []) as $key => $handler) {
                $registry->register((string) $key, $handler);
            }
            foreach (config('commerce.fulfillment.status_resolvers', []) as $key => $resolver) {
                $registry->registerStatusResolver((string) $key, $resolver);
            }
        });
        $this->callAfterResolving(PermissionRegistry::class, function (PermissionRegistry $registry): void {
            $registry->register('commerce', require __DIR__.'/../resources/permissions.php');
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../database/migrations' => database_path('migrations')], 'commerce-migrations');
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(ProductVariant::class, InventoryPolicy::class);
        Gate::policy(StockMovement::class, StockMovementPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(RefundRequest::class, RefundRequestPolicy::class);
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommerce::class, ...array_values(config('commerce.operations.commands', []))]);
            $this->publishes([__DIR__.'/../config/commerce.php' => config_path('commerce.php')], 'commerce-config');
        }
        config(['shopping-cart.models.skus.model' => config('commerce.cart.sku_model')]);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'commerce');
        if (config('commerce.routes.customer_delivery.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/customer.php');
        }
        $this->app->booted(function (): void {
            if (! config('commerce.operations.schedule.enabled', false)) {
                return;
            }
            $schedule = $this->app->make(Schedule::class);
            foreach (config('commerce.operations.schedule', []) as $key => $frequency) {
                $command = config('commerce.operations.commands.'.$key);
                if (! is_string($command) || ! class_exists($command) || ! is_string($frequency)) {
                    continue;
                }
                $event = $schedule->command($command);
                if (method_exists($event, $frequency)) {
                    $event->{$frequency}()->withoutOverlapping();
                }
            }
        });
    }
}
