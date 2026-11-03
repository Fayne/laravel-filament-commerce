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
php artisan vendor:publish --tag=commerce-config
php artisan migrate
```

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
