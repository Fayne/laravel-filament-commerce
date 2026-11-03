<?php

declare(strict_types=1);

namespace Fayne\Commerce\Returns\Actions;

use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Orders\Models\OrderLine;
use Fayne\Commerce\Returns\Enums\RefundStatus;
use Fayne\Commerce\Returns\Models\RefundRequest;
use Fayne\Commerce\Returns\Models\RefundRequestLine;
use Fayne\Commerce\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateRefundRequest
{
    private const RESERVED_STATUSES = ['pending_review', 'processing', 'unknown', 'succeeded'];

    public function __construct(private TenantContext $tenant) {}

    /** @param list<array{order_line_id: int, quantity: int}> $lines */
    public function execute(Order $order, array $lines, string $reason, string $idempotencyKey, ?int $requestedBy = null): RefundRequest
    {
        $tenantId = (int) $this->tenant->requireId();
        $reason = trim($reason);
        if ((string) $order->tenant_id !== (string) $tenantId || $lines === [] || $reason === '' || strlen($reason) > 500 || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['refund' => 'A tenant order, refund lines, reason, and idempotency key are required.']);
        }
        $requested = [];
        foreach ($lines as $index => $line) {
            $lineId = (int) ($line['order_line_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);
            if ($lineId < 1 || $quantity < 1 || isset($requested[$lineId])) {
                throw ValidationException::withMessages(['lines.'.$index => 'Each order line must appear once with a positive quantity.']);
            }
            $requested[$lineId] = $quantity;
        }
        ksort($requested);
        $fingerprint = hash('sha256', json_encode([$order->getKey(), $requested, $reason, $requestedBy], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($order, $requested, $reason, $idempotencyKey, $requestedBy, $fingerprint): RefundRequest {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $existing = RefundRequest::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for a different refund request.']);
                }

                return $existing->load('lines');
            }
            if ($lockedOrder->status !== 'confirmed' || $lockedOrder->payment_status !== 'paid') {
                throw ValidationException::withMessages(['order' => 'Only successfully paid orders can be refunded.']);
            }

            $orderLines = OrderLine::query()->where('order_id', $lockedOrder->getKey())->whereIn('id', array_keys($requested))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($orderLines->count() !== count($requested)) {
                throw ValidationException::withMessages(['lines' => 'A selected order line does not belong to this order.']);
            }
            $refund = RefundRequest::query()->create([
                'order_id' => $lockedOrder->getKey(), 'status' => RefundStatus::PENDING_REVIEW,
                'currency' => $lockedOrder->currency, 'amount' => 0, 'reason' => $reason,
                'idempotency_key' => $idempotencyKey, 'request_fingerprint' => $fingerprint, 'requested_by' => $requestedBy,
            ]);
            $totalAmount = 0;

            foreach ($requested as $lineId => $quantity) {
                $orderLine = $orderLines->get($lineId);
                $priorAllocations = RefundRequestLine::query()->where('order_line_id', $lineId)
                    ->whereHas('request', fn ($query) => $query->whereIn('status', self::RESERVED_STATUSES))
                    ->lockForUpdate()->get()->flatMap(fn (RefundRequestLine $line): array => $line->unit_allocations ?? []);
                $usedByAmount = [];
                foreach ($priorAllocations as $allocation) {
                    $key = (string) $allocation['unit_amount'];
                    $usedByAmount[$key] = ($usedByAmount[$key] ?? 0) + (int) $allocation['quantity'];
                }

                $groups = $orderLine->discount_units;
                if ($groups === []) {
                    $base = intdiv($orderLine->payable_amount, $orderLine->quantity);
                    $remainder = $orderLine->payable_amount % $orderLine->quantity;
                    $groups = array_values(array_filter([
                        ['net_unit_amount' => $base, 'quantity' => $orderLine->quantity - $remainder],
                        ['net_unit_amount' => $base + 1, 'quantity' => $remainder],
                    ], fn (array $group): bool => $group['quantity'] > 0));
                }
                $remainingToRefund = $quantity;
                $allocations = [];
                $lineAmount = 0;
                $lineRemaining = 0;
                foreach ($groups as $group) {
                    $unitAmount = (int) $group['net_unit_amount'];
                    $available = (int) $group['quantity'] - ($usedByAmount[(string) $unitAmount] ?? 0);
                    $lineRemaining += max(0, $available);
                    if ($available < 1 || $remainingToRefund < 1) {
                        continue;
                    }
                    $take = min($available, $remainingToRefund);
                    $allocations[] = ['unit_amount' => $unitAmount, 'quantity' => $take];
                    if ($unitAmount > intdiv(PHP_INT_MAX, $take)) {
                        throw ValidationException::withMessages(['lines.'.$lineId => 'Refund amount exceeds the supported integer range.']);
                    }
                    $lineAmount += $unitAmount * $take;
                    $remainingToRefund -= $take;
                }
                if ($quantity > $lineRemaining || $remainingToRefund !== 0) {
                    throw ValidationException::withMessages(['lines.'.$lineId => 'Refund quantity exceeds the remaining refundable quantity.']);
                }
                if ($lineAmount > PHP_INT_MAX - $totalAmount) {
                    throw ValidationException::withMessages(['lines' => 'Refund amount exceeds the supported integer range.']);
                }
                $totalAmount += $lineAmount;
                RefundRequestLine::query()->create([
                    'refund_request_id' => $refund->getKey(), 'order_line_id' => $lineId,
                    'quantity' => $quantity, 'amount' => $lineAmount, 'unit_allocations' => $allocations,
                ]);
            }

            $reservedAmount = RefundRequest::query()->where('order_id', $lockedOrder->getKey())->whereIn('status', self::RESERVED_STATUSES)->sum('amount');
            if ($totalAmount < 1 || $reservedAmount > $lockedOrder->payable_amount || $totalAmount > $lockedOrder->payable_amount - $reservedAmount) {
                throw ValidationException::withMessages(['refund' => 'The requested amount exceeds the remaining refundable amount.']);
            }
            $refund->update(['amount' => $totalAmount]);
            OutboxMessage::query()->create([
                'event_key' => 'refund.requested:'.$refund->getKey(),
                'event_type' => 'commerce.refund.requested',
                'payload' => ['order_id' => $lockedOrder->getKey(), 'refund_request_id' => $refund->getKey()],
                'available_at' => now(),
            ]);

            return $refund->load('lines');
        }, attempts: 3);
    }
}
