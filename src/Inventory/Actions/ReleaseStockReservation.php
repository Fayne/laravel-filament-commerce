<?php

declare(strict_types=1);

namespace Fayne\Commerce\Inventory\Actions;

use Fayne\Commerce\Inventory\Enums\StockReservationStatus;
use Fayne\Commerce\Inventory\Models\StockLevel;
use Fayne\Commerce\Inventory\Models\StockMovement;
use Fayne\Commerce\Inventory\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReleaseStockReservation
{
    public function execute(int $reservationId): StockReservation
    {
        return DB::transaction(function () use ($reservationId): StockReservation {
            $candidate = StockReservation::query()->findOrFail($reservationId);
            $level = StockLevel::query()->where('variant_id', $candidate->variant_id)->lockForUpdate()->firstOrFail();
            $reservation = StockReservation::query()->whereKey($reservationId)->lockForUpdate()->firstOrFail();
            if ($reservation->status === StockReservationStatus::RELEASED) {
                return $reservation;
            }
            if ($reservation->status !== StockReservationStatus::RESERVED) {
                throw ValidationException::withMessages(['reservation' => 'Only an active reservation can be released.']);
            }
            $level->decrement('reserved', $reservation->quantity);
            $reservation->update(['status' => StockReservationStatus::RELEASED, 'released_at' => now()]);
            StockMovement::query()->create(['variant_id' => $reservation->variant_id, 'source_type' => $reservation->source_type, 'source_id' => $reservation->source_id, 'reason' => 'release', 'on_hand_delta' => 0, 'reserved_delta' => -$reservation->quantity]);

            return $reservation->refresh();
        });
    }
}
