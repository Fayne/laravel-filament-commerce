# Commerce operations and delivery design

## Intent

Complete the reusable Commerce package workflows requested for stock operations, customer service order lookup, digital/service delivery access and revocation, and configurable package integrations. The package should provide safe defaults while allowing each host application to replace application-specific behavior through `config/commerce.php`.

## Current state

- Commerce has no package config file yet. `ConfirmOrderPayment`, `ExpirePendingOrders`, and `PublishCommerceOutbox` already exist.
- Shopping Cart provides `Fayne\ShoppingCart\Models\CartSku` as its standalone default. Its table has a globally unique SKU and no `tenant_id`, so it is not the model for a tenant Commerce catalog. Commerce provides `ProductVariant` implementing the generic `Cartable` contract and a tenant-scoped `CommerceSkuQuery`; Commerce depends on Shopping Cart, not vice versa.
- The selected Commerce integration configures Shopping Cart to use `ProductVariant` through the existing `Cartable` model setting and binds Commerce's tenant query and currency resolvers. This is host/package configuration at the composition boundary; Shopping Cart has no Commerce model dependency.
- Cart-to-order orchestration is not a Commerce action yet. Hosts currently map selected cart lines, quote them, place an order, and consume the cart within a transaction.
- Inventory domain actions and immutable stock movements exist, but there is no Filament inventory resource or general expired-reservation command.
- Orders use their ULID as the displayed, searchable order number. The Filament table already supports searching that value, but permission definitions omit `viewAnyOrder` and `viewOrder`, which `OrderPolicy` uses; `shipOrder` is registered but has no matching policy method.
- Digital/service delivery task creation, processing, and unknown-result reconciliation exist. There is no consumer result endpoint, revocation flow, or stuck-processing recovery command.
- Refund approval publishes `commerce.refund.approved`; `RecordRefundResult` records the host/payment result. Commerce does not call payment channels directly.
- Chinese and English README limitations contain stale Shopping Cart statements and need synchronization.

## Goals

1. Add Commerce defaults for payment confirmation, cart checkout, provider adapters, delivery handlers, and operational commands, with config-selected overrides.
2. Add a tenant-safe Filament stock view and auditable stock adjustment workflow, plus immutable stock movement history.
3. Expire overdue generic stock reservations safely. Existing order expiration continues to cancel unpaid orders and release their reservations.
4. Let administrators search orders by the existing order ULID under explicit view permissions.
5. Let authenticated tenant customers read successful delivery results for their own orders.
6. Let authorized staff request revocation of a successful delivery through a host-provided revoker, preserving idempotency and unknown outcomes.
7. Add safe recovery for delivery tasks left processing after a worker interruption; never blindly reissue an external delivery.
8. Update Chinese and English README to describe actual package behavior and host responsibilities.

## Non-goals

- Implement a payment provider, payment callback route, or provider refund HTTP call inside Commerce. Payment owns channel calls; Commerce owns refund eligibility, approval, and result recording.
- Implement carrier APIs, tracking synchronization, or shipping-rate calculation. Those belong to a logistics package; Commerce keeps shipment records and tracking fields.
- Add customer login pages/routes or a concrete SMS/WeChat provider. Host applications provide these adapters; package tests use fakes.
- Let guest orders read digital/service delivery results. Only an authenticated customer belonging to the order may read them.
- Add customer-group promotion rules or tax calculation in this phase.

## Approaches considered

1. **Host-only wiring:** expose contracts and require each application to implement every workflow. This maximizes host control but repeats cart checkout, operations scheduling, and handler registration, and provides no package-level defaults.
2. **Config-selected package defaults (selected):** Commerce supplies generic orchestration/actions and contracts, while `config/commerce.php` selects defaults and allows hosts to replace classes, adapters, commands, and schedules. This meets the requested balance of reusable behavior and application-specific integration.
3. **Hardwired provider/package integrations:** call a particular payment, cart, SMS, or logistics implementation directly. This is rejected because those contracts and credentials vary by application and would couple Commerce to provider details.

## Design decisions

### Configurable integration points

Add publishable `config/commerce.php` and merge it from `CommerceServiceProvider`. Keep business rules in Commerce actions and application/provider calls behind contracts. Config values select implementation class strings; custom classes must implement the corresponding contract. Bind defaults only when the host has not already supplied an explicit binding.

The config will include:

- `actions.payment_confirmation` defaulting to Commerce's amount/currency-verifying `ConfirmOrderPayment` workflow.
- `actions.cart_checkout` defaulting to a Commerce cart-to-order orchestrator that accepts validated buyer data, shipping snapshot, shipping amount, customer ID, locale, idempotency key, and reservation expiry from the host; it returns the created `Order`. It obtains selected cart lines from the configured Cartable model, requests a fresh Commerce quote, creates the order, and consumes the cart in the same database transaction. Client-supplied prices are never accepted. Order totals always come from Commerce's quote.
- `customers.guard` defaulting to `customer`; `customers.sms_sender` and `customers.mini_program_code_exchanger` remain host-selected adapters. Tests use fakes.
- `fulfillment.handlers`, `fulfillment.status_resolvers`, `fulfillment.revokers`, and `fulfillment.revocation_status_resolvers` maps keyed by stable handler key. Credentials are never stored in commerce product configuration.
- `operations.commands` defaults for outbox publishing, unpaid order expiration, generic reservation expiration, and stuck-delivery recovery. `operations.schedule` defaults to outbox and unpaid-order expiration every minute, generic reservation expiration every five minutes, and stuck-delivery recovery every five minutes after a ten-minute stale threshold. Scheduling is disabled by default so installation does not silently add recurring work; hosts can replace command classes or cadence in config. Custom command classes must retain a valid Laravel command signature.

Payment callback parsing and refund gateway calls remain in the host/payment package. Hosts call the configured Commerce payment-confirmation contract after successful provider verification. Approved Commerce refunds continue to publish the existing outbox event; a host listener calls its configured Payment package and reports the outcome through `RecordRefundResult`.

Cart checkout orchestration is reusable by default but remains replaceable through the configured action contract. Commerce does not own storefront HTTP endpoints or shipping-rate calculation. Cart, Commerce, and order tables must share the same transaction connection for atomic consumption.

The Commerce integration defaults the cart SKU model to `ProductVariant` in the host's Shopping Cart configuration. A host can replace that configured class with any tenant-scoped Eloquent model implementing `Cartable` and supply compatible tenant query/currency adapters. The Shopping Cart package's own standalone default remains `CartSku`.

Package scheduling is opt-in by config to avoid silently adding recurring work to an existing application's scheduler. The documented defaults provide command classes and cadence; enabling it registers those configured commands with Laravel's scheduler, using overlap protection.

### Inventory operations

- Add a tenant-scoped Filament stock resource listing physical SKU, on-hand, reserved, and available quantities. Stock rows are created lazily on the first adjustment; variants without a row appear with zero on-hand and zero reserved.
- Provide a stock adjustment action that calls `AdjustStock`, requires a reason, and records the actor through the CMS audit `Operations` service.
- Add a read-only stock movement resource showing adjustment, reservation, release, and commit history. Movement rows remain immutable.
- Register resource policies and CMS permissions for viewing stock, adjusting stock, and viewing movement history. The resource query and actions must reject cross-tenant records.
- Add a matching `shipOrder` policy ability so all registered order permissions are enforced.
- Add `commerce:inventory:expire-reservations` for overdue reservations not owned by Commerce orders. It calls `ReleaseStockReservation` idempotently. `ExpirePendingOrders` remains responsible for cancelling expired unpaid orders and releasing their order reservations, preventing payment/expiry races.

### Order lookup and authorization

- Keep the existing order ULID as the order number; add an explicit exact-match filter/search affordance for customer service.
- Register and test `tenant.viewAnyOrder` and `tenant.viewOrder` permissions used by `OrderPolicy`, and enforce the registered `tenant.shipOrder` permission. Tenant scoping remains enforced by the model/query and policy.
- Do not add a public order lookup endpoint. Customer service performs lookup in the authenticated Filament backend.

### Delivery result access

- Add a customer-authenticated, tenant-context-aware, paginated endpoint that lists delivery tasks for the authenticated customer's orders. Return task ID, order ID, SKU/product snapshot, unit number, status, and result; include the result payload only when status is `succeeded`. Pending, failed, unknown, and revoked tasks return no result payload.
- Bind the route to the configured customer guard and tenant middleware. The query must constrain tenant, order customer ID, and task status. Failure details, handler configuration, and internal exception text are never returned.
- Guest orders have no delivery-result access in this design.

### Delivery revocation and recovery

- Add separate `DeliveryRevoker` and `DeliveryRevocationStatusResolver` contracts so non-revocable product handlers do not need fake revoke methods.
- A staff action may request revocation only for a succeeded task and only when a revoker is registered for its stable handler key. Require the `tenant.revokeDelivery` permission. Duplicate requests while pending, processing, unknown, or succeeded return the existing attempt without another provider call; an explicit retry after definite failure gets a new attempt number and idempotency key.
- Persist a separate revocation lifecycle on the task (`pending`, `processing`, `succeeded`, `failed`, `unknown`), attempt count, stable idempotency key, external reference, encrypted result, failure reason, and timestamps. Keep original delivery status/result intact for audit and consumer history.
- Dispatch revocation through the existing outbox. A host listener invokes the configured revoker and records the result. Unknown results require explicit reconciliation through the configured status resolver; never blindly repeat a revoke call.
- Add a recovery command that moves stale `processing` delivery and revocation attempts to `unknown`, then permits explicit status reconciliation. It must not call `deliver()` or `revoke()` again.

### Cart SKU model decision

Selected: use the existing `Cartable` config and configure Commerce's host integration to use Commerce `ProductVariant`, while retaining Shopping Cart's independent `CartSku` default. `ProductVariant` already implements `Cartable`, and Commerce provides tenant-scoped SKU query and currency adapters. Hosts may override the configured Cartable model/adapters without changing either package. The Commerce package must not hard-code model knowledge into Shopping Cart.

## Acceptance criteria

- A clean host can use package defaults for configurable actions and command registrations after required config and migrations are installed; credentials and provider adapters remain host-configured.
- Overriding any configured action/adapter/command class uses the host implementation without changing package source.
- Cart checkout re-quotes current tenant SKU IDs, accepts only trusted host shipping/customer/expiry data, rejects tenant/currency/cart conflicts, and rolls back order creation if cart consumption fails.
- Stock adjustment creates one immutable movement with correct deltas, cannot lower on-hand below reserved quantity, writes audit context, and rejects cross-tenant records.
- Expiration releases due generic reservations once and leaves active/future reservations unchanged; order-owned reservations continue through order cancellation.
- An authorized admin can find an order by ULID; an unauthorized admin or another tenant cannot view it. Shipment actions require the registered `shipOrder` ability.
- A customer can read only successful delivery results for their own orders in the active tenant; guests and other customers receive no result data. Non-succeeded tasks never expose result payloads.
- Revocation is rejected for unsupported handlers or non-succeeded tasks, and duplicate requests/results are idempotent. Unknown revoke outcomes are not retried blindly.
- Stale delivery recovery marks attempts unknown without reissuing delivery; reconciliation can record succeeded, failed, or still unknown.
- Tests cover defaults, config overrides, tenant/customer isolation, failure rollback, idempotency, and missing-adapter behavior.
- Chinese and English README accurately distinguish package defaults from host-provided providers and explicitly state out-of-scope payment/logistics work.

## Open implementation detail

The customer endpoint will follow the package's route prefix conventions and return paginated task rows with `task_id`, `order_id`, SKU/product snapshot, unit number, status, and a result payload only for successful tasks. This does not change the authorization contract above.
