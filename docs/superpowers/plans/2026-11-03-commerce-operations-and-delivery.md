# Commerce Operations and Delivery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete configurable Commerce checkout/operations, inventory administration, customer service order access, and authenticated digital delivery results/revocation.

**Architecture:** Keep Commerce as the owner of tenant-scoped quotes, orders, inventory, and delivery state. Use package contracts and config-selected default classes for integrations; provider-specific payment, SMS, mini-program, and logistics calls remain outside Commerce.

**Tech Stack:** PHP, Laravel, Filament, Pest, CMS Core `TenantContext`/`Authorization`/`Operations`, Shopping Cart `Cartable`.

**Spec:** `docs/superpowers/specs/2026-11-03-commerce-operations-and-delivery-design.md`

## Global Constraints

- Do not modify `composer.lock`.
- Keep Shopping Cart independent from Commerce; Commerce may configure `ProductVariant` as its host SKU model through the Cartable contract.
- Always re-quote cart lines and use Commerce quote totals; never trust client prices.
- Provider refund requests remain an Outbox integration; Commerce does not call gateway APIs.
- Customer delivery results require an authenticated customer and tenant/order ownership; guest results remain inaccessible.
- External delivery/revocation calls use idempotency keys and unknown outcomes are never blindly retried.
- Update both Chinese and English Commerce README files.

## Review Focus

- Cart checkout consumes only the selected projection and fully rolls back order/stock/coupon writes if cart consumption fails.
- Expiration and payment confirmation races cannot double-release or commit reservations.
- Inventory queries, stock adjustments, order search, and task results cannot cross tenant boundaries.
- Delivery revocation retries and stale processing recovery never invoke an external provider twice without an explicit safe retry/reconciliation.
- Result payloads are absent for every task state except successful delivery and are never returned to another customer or a guest.

---

### Task 1: Configurable Commerce integrations and cart checkout

**Files:**
- Create: `config/commerce.php`
- Create: `src/Orders/Contracts/PaymentConfirmation.php`
- Create: `src/Checkout/Contracts/CartCheckout.php`
- Create: `src/Checkout/DTO/CartCheckoutData.php`
- Create: `src/Checkout/Actions/CheckoutCart.php`
- Create: `src/Cart/CommerceCartContextResolver.php`
- Modify: `src/Orders/Actions/ConfirmOrderPayment.php`
- Modify: `src/CommerceServiceProvider.php`
- Test: `tests/Feature/ConfigurableIntegrationTest.php`
- Test: `tests/Feature/CheckoutCartTest.php`

**Interfaces:**
- `PaymentConfirmation::execute(string $orderId, string $provider, string $providerReference, int $amount, string $currency): Order`; default implementation is `ConfirmOrderPayment`.
- `CartCheckout::execute(CartCheckoutData $data): Order`; default implementation accepts trusted buyer, shipping snapshot/amount, optional customer ID, locale, idempotency key and reservation expiry.
- `CommerceCartContextResolver` reads the trusted CMS tenant, configured customer guard, and server-side guest session ID.

- [ ] Write failing tests for default/overridden contract resolution, config-selected Cartable SKU model, and Commerce cart context resolver.
- [ ] Run the new config/context tests and confirm they fail because config and bindings do not exist.
- [x] Add `commerce.php`, publish/merge it in `CommerceServiceProvider`, bind configurable action and provider contracts, configure Shopping Cart's SKU model to the configured Commerce Cartable model, and bind `CommerceSkuQuery`, `TenantCurrency`, and `CommerceCartContextResolver` defaults with host override support.
- [x] Run the focused config/context tests and confirm defaults and overrides pass.
- [ ] Write failing cart checkout tests for refreshed quote values, selected lines only, host-supplied shipping data, successful consume, and rollback when consume fails.
- [ ] Run those tests and confirm the checkout action is missing.
- [x] Implement `CheckoutCart` to read selected lines, re-quote via `QuoteProducts`, place via `PlaceOrder`, and consume cart lines in the same connection transaction; reject an empty selected projection.
- [x] Run checkout tests and the Commerce feature suite; confirm all pass.

### Task 2: Inventory administration and reservation expiration

**Files:**
- Create: `src/Filament/Resources/StockLevelResource.php`
- Create: `src/Filament/Resources/StockMovementResource.php`
- Create: `src/Filament/Resources/StockLevels/Pages/ListStockLevels.php`
- Create: `src/Filament/Resources/StockMovements/Pages/ListStockMovements.php`
- Create: `src/Policies/InventoryPolicy.php`
- Create: `src/Console/Commands/ExpireStockReservations.php`
- Modify: `src/CommercePlugin.php`
- Modify: `src/CommerceServiceProvider.php`
- Modify: `src/Filament/Resources/OrderResource.php`
- Modify: `src/Policies/OrderPolicy.php`
- Modify: `resources/permissions.php`
- Test: `tests/Feature/InventoryAdministrationTest.php`
- Test: `tests/Feature/ExpireStockReservationsTest.php`

**Interfaces:**
- Stock UI uses `ProductVariant` rows with a left-joined stock level; missing levels display zero and are created by the existing `AdjustStock` action.
- Movement history is read-only and uses `StockMovement`.
- `commerce:stock:expire-reservations {--limit=100}` releases overdue `reserved` records whose `source_type` is not `commerce_order`; order expiration stays in `ExpirePendingOrders`.

- [ ] Write failing tests for tenant-scoped stock list visibility, adjustment authorization/audit/movement deltas, lowering below reserved rejection, and immutable movement history.
- [ ] Run the inventory tests and confirm the resources and permissions are missing.
- [x] Implement stock and movement Filament resources with CMS authorization, audit logging, and tenant-safe queries; register them in `CommercePlugin`.
- [x] Run inventory tests and confirm pass.
- [ ] Write failing expiration tests for due generic reservation, future reservation, already released reservation, and order-owned reservation exclusion.
- [ ] Run the expiration tests and confirm the command is missing.
- [x] Implement the bounded per-tenant expiration command through `ReleaseStockReservation`, register the command from config, and add schedule defaults with opt-in scheduling.
- [x] Add `OrderPolicy::ship` and the `tenant.shipOrder` permission. (The permission exists; the full policy authorization matrix remains for follow-up tests.)
- [x] Run inventory and Commerce feature tests; 32 tests and 90 assertions pass.

### Task 3: Customer service order search and permissions

**Files:**
- Modify: `src/Filament/Resources/OrderResource.php`
- Modify: `src/Policies/OrderPolicy.php`
- Modify: `resources/permissions.php`
- Modify: `lang/zh_CN/orders.php`
- Modify: `lang/en/orders.php`
- Test: `tests/Feature/OrderAuthorizationTest.php`

**Interfaces:**
- Keep the ULID as the order number; use exact-match searchable filtering in the authenticated Filament resource.
- Order list/view requires `tenant.viewAnyOrder`/`tenant.viewOrder`; shipment uses `tenant.shipOrder`.

- [ ] Write failing policy/resource tests for missing view permissions, successful exact ULID lookup, unauthorized access, and cross-tenant denial.
- [ ] Run the order authorization tests and confirm the permission catalog/resource does not satisfy them.
- [x] Add permission entries for all/editor/viewer roles and an exact order number filter. (Localized order-number filter placeholder is part of the inventory translation catalogs.)
- [ ] Run the order authorization and Filament Commerce tests; confirm pass.

### Task 4: Customer delivery results, revocation, and recovery

**Files:**
- Create: `src/Fulfillment/Contracts/DeliveryRevoker.php`
- Create: `src/Fulfillment/Contracts/DeliveryRevocationStatusResolver.php`
- Create: `src/Fulfillment/Actions/RequestDeliveryRevocation.php`
- Create: `src/Fulfillment/Actions/ProcessDeliveryRevocation.php`
- Create: `src/Fulfillment/Actions/RecordDeliveryRevocationResult.php`
- Create: `src/Fulfillment/Actions/ReconcileDeliveryRevocation.php`
- Create: `src/Fulfillment/Actions/RecoverStaleDeliveryTasks.php`
- Create: `src/Console/Commands/RecoverDeliveryTasks.php`
- Create: `src/Http/Controllers/CustomerDeliveryTaskController.php`
- Create: `routes/api.php`
- Create: `database/migrations/2026_11_03_000012_add_delivery_revocation_state.php`
- Modify: `src/Fulfillment/DeliveryHandlerRegistry.php`
- Modify: `src/Fulfillment/Models/DeliveryTask.php`
- Modify: `src/CommerceServiceProvider.php`
- Modify: `src/Filament/Resources/Orders/Pages/ViewOrder.php`
- Modify: `src/Policies/OrderPolicy.php`
- Modify: `resources/permissions.php`
- Test: `tests/Feature/CustomerDeliveryResultsTest.php`
- Test: `tests/Feature/DeliveryRevocationTest.php`
- Test: `tests/Feature/RecoverDeliveryTasksTest.php`

**Interfaces:**
- Customer endpoint: paginated `GET` route under the Commerce API prefix and configured customer guard; tenant middleware is host-configurable. Response fields: task/order IDs, SKU/product snapshot, unit number, status, and result only for `succeeded`.
- Revocation result states: `succeeded`, `failed`, or `unknown`; attempts use `commerce:delivery-revoke:{taskId}:{attempt}` idempotency keys.
- Repeated requests in pending/processing/unknown/succeeded return the current attempt. Only an explicit retry after definite failure creates a new attempt. Unknown/stale processing can only be resolved by a registered status resolver.
- Recovery marks stale delivery/revocation `processing` as `unknown`; it never re-runs delivery or revoke handlers.

- [ ] Write failing customer endpoint tests for own successful task, other customer, other tenant, guest, and non-succeeded result redaction.
- [ ] Run endpoint tests and confirm route/controller are missing.
- [x] Add configurable authenticated route/controller and tenant/customer-scoped query; expose result only for succeeded tasks.
- [x] Verify the route is registered with the configured customer guard middleware. (Full database-backed endpoint isolation tests remain.)
- [ ] Write failing revocation tests for unsupported handler, non-succeeded task, duplicate request, successful/failed/unknown result, and explicit retry after definite failure.
- [ ] Run revocation tests and confirm revocation state/contracts are missing.
- [ ] Add migration, contracts, registry config loading, Outbox-backed request/process/result actions, status reconciliation, and an authorized Filament revoke action.
- [ ] Run revocation tests and confirm pass.
- [ ] Write failing recovery tests for stale and fresh processing tasks and verify no handler is invoked during recovery.
- [ ] Add recovery command and configured schedule; run recovery tests.
- [ ] Run all Commerce feature tests; confirm pass.

### Task 5: Configured operations, documentation, and final verification

**Files:**
- Modify: `src/CommerceServiceProvider.php`
- Modify: `config/commerce.php`
- Modify: `README.md`
- Modify: `README.en.md`
- Test: `tests/Feature/CommerceConfigurationTest.php`

- [ ] Write failing tests for configurable command class registration and opt-in scheduled command cadence.
- [ ] Run the tests and confirm package commands are hard-coded and scheduling is absent.
- [x] Register config-selected outbox, order-expiration, stock-reservation-expiration, and delivery-recovery command classes; register default cadence only when `commerce.operations.schedule.enabled` is true, with overlap protection.
- [ ] Run config tests and confirm pass.
- [x] Update both READMEs with current implementation and remaining boundaries.
- [x] Run Pint on Commerce package files and the full Commerce Pest suite (32 tests, 90 assertions).
- [ ] Review the final diff against the spec and ensure no `composer.lock` or host application files changed.

## Final verification

Run from `packages/laravel-filament-commerce` using the Herd PHP binary:

- `php ../../vendor/bin/pest --no-coverage --compact`
- PHP syntax checks over `src`, `config`, `database/migrations`, `routes`, `lang`, and `tests`
- `composer validate --no-check-publish --no-check-lock`
- Pint only on changed package files
- `git diff --check`
