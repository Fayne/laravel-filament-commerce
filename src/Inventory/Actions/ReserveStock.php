<?php

declare(strict_types=1);

namespace Fayne\Commerce\Inventory\Actions;

use Fayne\Commerce\Catalog\Enums\ProductType;
use Fayne\Commerce\Catalog\Models\ProductVariant;
use Fayne\Commerce\Inventory\Enums\StockReservationStatus;
use Fayne\Commerce\Inventory\Models\StockLevel;
use Fayne\Commerce\Inventory\Models\StockMovement;
use Fayne\Commerce\Inventory\Models\StockReservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReserveStock
{
    public function execute(ProductVariant $variant, int $quantity, string $sourceType, string $sourceId, ?Carbon $expiresAt = null): StockReservation
    {
        if ($variant->product?->type !== ProductType::PHYSICAL) {
            throw ValidationException::withMessages(['variant' => 'Inventory reservation is only available for physical products.']);
        }
        if ($quantity < 1 || trim($sourceType) === '' || trim($sourceId) === '') {
            throw ValidationException::withMessages(['reservation' => 'A positive quantity and source reference are required.']);
        }

        return DB::transaction(function () use ($variant, $quantity, $sourceType, $sourceId, $expiresAt): StockReservation {
            $level = StockLevel::query()->where('variant_id', $variant->getKey())->lockForUpdate()->firstOrFail();
            $existing = StockReservation::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->where('variant_id', $variant->getKey())->lockForUpdate()->first();
            if ($existing) {
                if ($existing->quantity !== $quantity || $existing->status !== StockReservationStatus::RESERVED || ($existing->expires_at && $existing->expires_at->isPast())) {
                    throw ValidationException::withMessages(['reservation' => 'The source already has a different or inactive stock reservation.']);
                }

                return $existing;
            }
            if ($level->available() < $quantity) {
                throw ValidationException::withMessages(['quantity' => 'Insufficient available stock.']);
            }

            $level->increment('reserved', $quantity);
            $reservation = StockReservation::query()->create([
                'variant_id' => $variant->getKey(), 'source_type' => $sourceType, 'source_id' => $sourceId,
                'quantity' => $quantity, 'status' => StockReservationStatus::RESERVED, 'expires_at' => $expiresAt,
            ]);
            StockMovement::query()->create(['variant_id' => $variant->getKey(), 'source_type' => $sourceType, 'source_id' => $sourceId, 'reason' => 'reserve', 'on_hand_delta' => 0, 'reserved_delta' => $quantity]);

            return $reservation;
        });
    }
}
