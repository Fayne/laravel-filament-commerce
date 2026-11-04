<p align="center"><img src="https://res.cloudinary.com/fayne/image/upload/v1787643269/fayne_logo.png" width="300"></p>

# Laravel Filament Commerce

[简体中文](../README.md) | English

A commerce package for **PHP 8.4+, Laravel 13, and Filament 5** with tenant isolation.

## Features and scope

- Tenant-scoped customer, product, SKU, and product translation models. Phone numbers and mini program identities are unique per tenant, so one person can have independent accounts in different stores.
- Transactional order creation with purchase snapshots and idempotency, for guest contact details or a tenant customer reference.
- Physical, digital, and service product types.
- Product form locales follow the tenant's CMS content-language settings; the default-locale product name is required.
- SKU prices use integer minor units. The default currency is CNY (fen for RMB); set a tenant currency with `app(TenantCurrency::class)->set()`, which is locked after SKU prices exist.
- Product, Order, RefundRequest, Stock, and StockMovement resources use the CMS tenant permission catalog. Orders support cancellation, partial shipments, and quantity-based partial refund requests; stock and order operations are recorded through the host audit system.
- Product, order, refund, stock, and stock movement resources are registered on a target Filament Panel through `CommercePlugin`. Order details show each digital/service unit's delivery status without exposing sensitive results.
- Payment confirmation creates one delivery task per purchased unit for digital products and services. Tasks use a stable handler key, an idempotency key, and encrypted results; the host supplies the actual provisioning logic.

## 1. Installation

Configure the private Composer source for this package and its Fayne dependencies, then run:

```bash
composer require fayne/laravel-filament-commerce
php artisan ec:core:install
```

The installer publishes missing Commerce configuration, runs host migrations, and initializes the CMS permission catalog. For automated production deployments, use `php artisan ec:core:install --force --no-interaction`.

In local, testing, and staging, the installer can add two sample physical products and stock, plus cancelled, unshipped, partially shipped, and fully shipped demo orders. Orders are snapshots and do not trigger payment or shipping integrations. The prompt defaults to no. Use `--demo --no-interaction` for automation and include `--tenant=<tenant-id>` in tenant mode. Existing demo SKUs and orders are skipped.


The package depends on the host's `fayne/laravel-filament-cms-core` package and uses its `TenantContext`, `BelongsToTenant`, and `ContentLocale`. In tenant mode, CMS Core must establish a trusted tenant context before queries; missing context is rejected.

Register on the target Filament Panel Provider:

```php
use Fayne\Commerce\CommercePlugin;

return $panel->plugin(CommercePlugin::make());
```

Run the host migrations to create the commerce domain tables. Package policies use the CMS tenant permission catalog; the host assigns permissions according to its business rules.

## Current limitations

This package is under development. Customer SMS/mini program login HTTP endpoints, an SMS provider, tenant mini program credential management, Payment callback wiring, carrier tracking, and automatic gateway refund execution remain unfinished. Cart checkout integration, inventory administration, expired reservation cleanup, and recovery of interrupted processing tasks are implemented. The customer delivery endpoint only exposes successful results for the authenticated customer's own orders in the active tenant. Cart SKU/amount tenant contracts and Payment merchant isolation still require host integration verification. Checkout requires host-provided address, shipping amount, and reservation expiry. Guest orders are available to authorized backend support staff; guests cannot read delivery results. Revocation requires host provider adapters. Never store external service credentials in delivery configuration.

## Tenant customer accounts

Customer accounts use a separate `Customer` model and auth guard from CMS administrators. After SMS code verification, the account is created automatically if the phone has no account in the current tenant. Establish the CMS `TenantContext` before requesting a code:

```php
// config/auth.php
'guards' => ['customer' => ['driver' => 'session', 'provider' => 'commerce_customers']],
'providers' => ['commerce_customers' => ['driver' => 'eloquent', 'model' => \Fayne\Commerce\Customers\Models\Customer::class]],

// Tenant storefront controller
app(\Fayne\Commerce\Customers\Actions\SendPhoneVerificationCode::class)->execute('+8613800000000');
$customer = app(\Fayne\Commerce\Customers\Actions\AuthenticateCustomerByPhoneCode::class)
    ->execute('+8613800000000', $verificationCode);
\Illuminate\Support\Facades\Auth::guard('customer')->login($customer);
request()->session()->regenerate();
```

Eleven-digit mainland China numbers are normalized to `+86`; other international numbers must include a `+` country code. SMS delivery is provided by a host binding for `Fayne\Commerce\Customers\Contracts\SmsCodeSender`. Codes expire after five minutes, have a 60-second resend cooldown, allow at most 10 sends per phone per day, and permit five checks per code; add IP-based throttling to the HTTP endpoint as well. Only a code hash is stored. Configure a separate session guard/provider for Customer and ensure tenant resolution middleware runs before the guard retrieves a user. The verification action returns a Customer but does not log into the session.

Mini program login uses `AuthenticateCustomerByMiniProgramCode`; the host then logs the returned Customer into the same guard. Bind `MiniProgramCodeExchanger` to exchange a code with WeChat using credentials for the trusted active tenant; never accept appid/openid from the client. CMS Core's `WechatMiniAppSession` is for administrator login and uses a global app config, so it must not be reused for tenant customers. `BindPhoneToCustomer` and `BindMiniProgramToCustomer` let an already authenticated customer bind the other login method; accounts created separately are not merged automatically, and no historical account merge flow is included. SMS vendor integration, tenant mini program credential storage/management, and HTTP login routes/pages remain host work. Guest orders continue to use `customerId: null`; authenticated checkout must pass an active customer ID from the current tenant.

## Checkout quote

`QuoteProducts` loads current SKU prices and translated names for the active tenant, checks product availability and physical stock, evaluates Promotion rules, and returns line-level discount/refund allocations. It is a side-effect-free preview: it does not lock stock, create an order, or reserve a coupon.

```php
$quote = app(\Fayne\Commerce\Pricing\Actions\QuoteProducts::class)->execute(
    requestedLines: [['variant_id' => 12, 'quantity' => 2]],
    shippingAmount: 0, // supplied by trusted host shipping logic, never a client amount
    couponCode: null,
    customerId: null,
    locale: null, // tenant default locale, or an enabled locale
);
```

Taxes are not calculated, and customer groups are not loaded yet, so group-targeted promotions are not supported by this quote path. Stock preview is unlocked; order placement must reprice and reserve stock atomically.

## Place an order

Orders must use a quote created by Commerce. Guest checkout requires a name and a valid email or phone. Authenticated consumers are linked through a tenant customer ID. `shippingAddress` must be a host-validated snapshot and is required for physical products. The host supplies the reservation expiry based on its checkout timeout policy.

```php
$order = app(\Fayne\Commerce\Orders\Actions\PlaceOrder::class)->execute(
    quote: $quote,
    buyer: ['name' => 'Lin', 'phone' => '+8613800000000'],
    shippingAddress: ['recipient' => 'Lin', 'phone' => '+8613800000000', 'address' => '...'],
    idempotencyKey: $checkoutRequestId,
    reservationExpiresAt: now()->addMinutes(15),
);
```

Order creation saves product, price, promotion, and delivery snapshots; reserves physical stock and coupon capacity; and writes an Outbox event in the same database transaction. The payment gateway has not yet been wired to the confirmation action. Host listeners for `CommerceOutboxEvent` must restore a trusted tenant context using the event tenant ID before handling the event. Confirmation validates amount/currency and commits stock/coupon reservations idempotently; late payments enter `payment_review` and do not trigger fulfilment. Publish the Outbox with `commerce:outbox:publish` and expire unpaid orders with `commerce:orders:expire-reservations`; the host must schedule both commands. Outbox delivery is at least once, so listeners must be idempotent by eventKey. The expiry above is illustrative; the host chooses the actual duration.

## Basic inventory actions

The inventory actions can currently be called by host services:

```php
$level = app(\Fayne\Commerce\Inventory\Actions\AdjustStock::class)
    ->execute($variant, newOnHand: 20, reason: 'Cycle count');

$reservation = app(\Fayne\Commerce\Inventory\Actions\ReserveStock::class)
    ->execute($variant, quantity: 2, sourceType: 'checkout', sourceId: 'request-123', expiresAt: now()->addMinutes(15));

app(\Fayne\Commerce\Inventory\Actions\CommitStockReservation::class)->execute($reservation->id);
// Release when an unpaid checkout is cancelled:
app(\Fayne\Commerce\Inventory\Actions\ReleaseStockReservation::class)->execute($reservation->id);
```

Reservations are idempotent by `sourceType + sourceId + SKU`. Committing decreases both on-hand and reserved quantities; releasing decreases only the reserved quantity. Expired reservations cannot be committed. The inventory page lets administrators with `tenant.adjustStock` adjust stock; every change is written to the read-only movement history and host audit log. `commerce:stock:expire-reservations` releases due reservations per tenant. Order-owned reservations remain handled by order expiration. Scheduling is disabled by default and can be enabled via `commerce.operations.schedule.enabled`.


## Partial shipments

For paid or zero-value confirmed orders, staff can create multiple shipment records with quantities per physical order line. Each operation locks the order and lines, ensures cumulative shipped quantity does not exceed purchased quantity, and subtracts quantities reserved by pending, processing, unknown, or successful refund requests; failed and rejected requests release their quantities. Shipment records store carrier/tracking details and are idempotent. The order detail page lists each shipment's status, carrier, tracking number, shipped time, and quantities per order line. Digital and service lines do not use shipping actions. Carrier tracking sync is not implemented yet.

## Digital product and service delivery

The host registers a stable key to a handler class; the database stores the key, never an executable class name. Implement `Fayne\Commerce\Fulfillment\Contracts\DeliveryHandler` and process Outbox events through `ProcessDeliveryTask`. Register a `DeliveryStatusResolver` for external status checks and use `ReconcileDeliveryTask`. Config snapshots and results are encrypted at rest. `GET /api/commerce/customer/deliveries` uses the configured customer guard and returns only the authenticated customer's deliveries in the active tenant; only succeeded task results are included. The `commerce:deliveries:recover` command marks stale processing tasks unknown and never retries external delivery. Revocation requires host provider adapters.

## Quantity-based partial refund requests

Paid orders can receive refund requests by order-line quantity. Amounts use the per-unit net price and discount allocation snapshot from checkout. Pending review, processing, unknown, and successful requests reserve quantity and amount capacity to prevent duplicate over-refunds. Staff can approve or reject requests in Filament. Approval changes the status to `processing` and publishes a `commerce.refund.approved` Outbox event. A host listener calls the appropriate payment channel and uses `RecordRefundResult` to record `succeeded`, `failed`, or `unknown`. Unknown results continue to reserve refundable capacity; rejection and definite failure release it. Outbox delivery is at least once, so host handlers must be idempotent. Gateway calls, shipping refunds, and return approval are not built in.

## Shopping Cart

Configure `Fayne\Commerce\Catalog\Models\ProductVariant` as the cart SKU model and bind `Fayne\ShoppingCart\Contracts\CartCurrencyResolver` to `Fayne\Commerce\Tenancy\Actions\TenantCurrency`. Resolve cart identity using the same CMS tenant context and the `customer` guard. Commerce quotes and creates the order, then consumes cart lines inside the same order transaction.

Bind `Fayne\ShoppingCart\Contracts\CartSkuQuery` to `Fayne\Commerce\Cart\CommerceSkuQuery` to constrain cart SKU queries by the trusted tenant ID.

For an independent customer table, set `CART_ENFORCE_USER_FOREIGN_KEY=false` before running the initial cart migration. The host owns cart cleanup on customer deletion. The customer key remains an integer.

## Delivery revocation

Run package migrations and grant `tenant.revokeDelivery` to administrators who can request revocation from the order details page. Configure `commerce.fulfillment.revokers` and `commerce.fulfillment.revocation_status_resolvers` by delivery handler key. Listen for `commerce.delivery.revocation_requested` and call `ProcessDeliveryRevocation::execute()` with the event's `delivery_task_id` and `attempt` in its tenant context. The `commerce:deliveries:recover` command marks stale attempts unknown. Call `ProcessDeliveryRevocation::reconcile()` to query the provider. Staff can request a new attempt after a definite failure.

## Package checks

Run `composer test` and `composer format` from the package root. In GitHub Actions, set the repository variable `PRIVATE_COMPOSER_URL` to the Composer registry that serves the private dependencies and supply credentials through the `COMPOSER_AUTH` secret if required.

## License

This software is distributed under the [proprietary license](../LICENSE.md).

Installation adds Commerce baseline permissions to existing built-in tenant manager, content editor, and viewer roles, plus single-site admin and editor roles. Other permissions are preserved. Administrators manage custom roles explicitly. Reinstallation restores missing Commerce baseline grants on built-in roles. Administrator access follows active tenant membership and scoped role assignments.


Permission labels and groups are declared by this package in `resources/permissions.php`, with English and Simplified Chinese translations in `resources/lang`. CMS Core resolves the registered metadata without business-name matching.

### Initial database schema

The migrations target fresh installations. Customer identities, payment state, cancellation reasons, encrypted text columns, and delivery revocation state are included in initial table creation. Migrations follow dependency order and support reverse rollback. Development databases installed using the previous migration set require a backed-up rebuild and reinitialization. This baseline does not upgrade existing business databases. Include host migration records and previous migration filenames in the rebuild procedure.

Successful installation clears the Filament component cache so registered Commerce resources become available in the panel. Production deployments using this cache should run `php artisan filament:cache-components` after panel configuration and installation.

## Product extensions

Register ProductExtension subclasses in commerce.extensions.products. The container resolves each class. key() declares a unique identifier, fields(slot) supports basic/content, and tabs() returns additional Filament tabs. Use field paths such as extensions.details.origin and relative rules such as origin => required|string|max:100.

Run php artisan migrate for the tenant-scoped extension JSON table. SaveProduct validates and saves within its transaction. Submitted namespaces replace their complete payload; omitted namespaces validate and preserve existing data. Required rules apply to creation. Explicitly declare allowed nested array keys.

Override fill(Product) and save(Product, array) for dedicated storage. Adapters must use the same database connection, enforce tenant-safe relationships and configure sensitive audit filtering. Product extensions are not automatically exposed through public APIs. Translation and SKU extension contracts are deferred.

## SKU extensions

Register SkuExtension subclasses in commerce.extensions.skus. key() declares a unique namespace, fields() returns Filament fields inside each variant Repeater row, and rules() defines relative validation rules. For example, extension_data.logistics.barcode uses the logistics key with a barcode rule. Field validation gives UI feedback; model-save validation applies to all Eloquent saving entry points.

Run php artisan migrate to add the SKU extension_data JSON column. Data is hydrated with each variant on edit/view. Only validated configured fields are accepted. Missing namespaces and disabled extensions preserve stored values. Submitted namespaces replace their entire payload. Explicit null requires nullable validation. Declare allowed nested array keys. Product create/edit pages use database transactions so a failed SKU rolls back the entire form save.

This provides SKU JSON metadata. Indexed unique barcodes and related entities need dedicated host columns and business actions. Custom SKU storage adapters are not included. These fields are not automatically copied into cart or order snapshots.

The product extension table and SKU extension_data column are included in the initial catalog migration. Fresh installs run the baseline directly. Development databases using earlier schemas require rebuilding as described in the initial schema section; migrate does not modify already-executed table creation migrations.

## Complete Product and SKU extension example

This example adds origin and care instructions to products, plus barcode and weight to each SKU. Create the following classes in the host application.

### 1. ProductDetails

app/Commerce/Extensions/ProductDetails.php

```php
<?php

namespace App\Commerce\Extensions;

use Fayne\Commerce\Catalog\Extensions\ProductExtension;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs\Tab;

class ProductDetails extends ProductExtension
{
    public function key(): string
    {
        return 'details';
    }

    public function fields(string $slot): array
    {
        return match ($slot) {
            'basic' => [
                TextInput::make('extensions.details.origin')
                    ->label(__('commerce_extensions.origin'))
                    ->maxLength(100),
            ],
            default => [],
        };
    }

    public function tabs(): array
    {
        return [
            Tab::make(__('commerce_extensions.specifications'))->schema([
                Textarea::make('extensions.details.care_instructions')
                    ->label(__('commerce_extensions.care_instructions'))
                    ->rows(4)->columnSpanFull(),
            ]),
        ];
    }

    public function rules(): array
    {
        return [
            'origin' => ['nullable', 'string', 'max:100'],
            'care_instructions' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

The origin field appears in the basic tab. Care instructions appear in an additional tab. Both use the same details namespace. The content slot can also insert fields into the existing Content and SEO tab.

### 2. SkuLogistics

app/Commerce/Extensions/SkuLogistics.php

```php
<?php

namespace App\Commerce\Extensions;

use Fayne\Commerce\Catalog\Extensions\SkuExtension;
use Filament\Forms\Components\TextInput;

class SkuLogistics extends SkuExtension
{
    public function key(): string
    {
        return 'logistics';
    }

    public function fields(): array
    {
        return [
            TextInput::make('extension_data.logistics.barcode')
                ->label(__('commerce_extensions.barcode'))
                ->maxLength(64),
            TextInput::make('extension_data.logistics.weight_grams')
                ->label(__('commerce_extensions.weight_grams'))
                ->numeric()->integer()->minValue(0),
        ];
    }

    public function rules(): array
    {
        return [
            'barcode' => ['nullable', 'string', 'max:64'],
            'weight_grams' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
```

Fields appear inside each SKU row. Paths are relative to the row, so do not prefix them with variants or a row index. Model casts hydrate the saved values on edit and view.

### 3. Register the classes

```php
// config/commerce.php
'extensions' => [
    'products' => [App\Commerce\Extensions\ProductDetails::class],
    'skus' => [App\Commerce\Extensions\SkuLogistics::class],
],
```

Keep the other configuration entries. Unique extension keys must stay stable after data is stored.

### 4. Host translations

```php
<?php
// lang/zh_CN/commerce_extensions.php
return [
    'origin' => '产地',
    'specifications' => '扩展信息',
    'care_instructions' => '保养说明',
    'barcode' => '条码',
    'weight_grams' => '重量（克）',
];
```

```php
<?php
// lang/en/commerce_extensions.php
return [
    'origin' => 'Origin',
    'specifications' => 'Additional details',
    'care_instructions' => 'Care instructions',
    'barcode' => 'Barcode',
    'weight_grams' => 'Weight (grams)',
];
```

These labels follow the administrator interface language. Extension values in this example are shared product/SKU attributes; they do not change with the content language.

### 5. Prepare and verify

```bash
php artisan config:clear
php artisan migrate
```

For fresh baseline installations, migrations create both storage locations. Previously installed development schemas need the documented rebuild procedure. Open a product, fill both product fields and two SKU rows with different barcode/weight values, save, reopen, and inspect the read-only product view.

### Persistence and validation

Product data is stored in commerce_product_extensions by tenant/product/key; read it with app(ProductExtensions::class)->fill($product). SKU values are available as $variant->extension_data['logistics']. Form rules provide immediate feedback, while extension rules validate all supported saving entry points. Add corresponding required rules in both locations for mandatory fields. Adding required fields to existing catalogs requires a backfill.

A submitted namespace replaces its complete payload. Omitted namespaces preserve existing values; nullable fields can be cleared explicitly. Only validated fields are saved. Nested arrays must declare allowed keys. Barcode uniqueness and weight-based shipping/cart behavior require dedicated host integration. Custom Product fill/save adapters must use the same connection and enforce tenant-safe relations. SKU custom storage adapters are not available.

## Automatic CMS module registration

With CMS Core installed, this package registers its module through its Laravel ServiceProvider. Panels extending `Fayne\CMS\Providers\AdminPanelProvider` / `TenantPanelProvider` automatically connect installed modules: business UI belongs to the ordinary panel in single-site mode and the workspace in multi-tenant mode. Installation grants no account permissions; policies still control navigation and access.

In a fully custom PanelProvider, configure explicit plugins first, then return `app(\Fayne\CMS\Support\Modules\ModuleRegistry::class)->applyTo($panel)`. An explicitly configured plugin with the same ID is preserved. Do not register the same business plugin again after applying the registry. Earlier manual Plugin examples remain applicable to standalone or fully custom panels.

Registered modules are enabled by default in this phase. There is no module-management UI, online installation/removal or runtime API/queue disabling. 
