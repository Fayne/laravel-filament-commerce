<?php

declare(strict_types=1);

namespace Fayne\Commerce\Inventory\Actions;

use Fayne\Commerce\Inventory\Enums\StockReservationStatus;
use Fayne\Commerce\Inventory\Models\StockLevel;
use Fayne\Commerce\Inventory\Models\StockMovement;
use Fayne\Commerce\Inventory\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommitStockReservation
{
    public function execute(int $reservationId): StockReservation
    {
        return DB::transaction(function () use ($reservationId): StockReservation {
            $candidate = StockReservation::query()->findOrFail($reservationId);
            $level = StockLevel::query()->where('variant_id', $candidate->variant_id)->lockForUpdate()->firstOrFail();
            $reservation = StockReservation::query()->whereKey($reservationId)->lockForUpdate()->firstOrFail();
            if ($reservation->status === StockReservationStatus::COMMITTED) {
                return $reservation;
            }
            if ($reservation->status !== StockReservationStatus::RESERVED || ($reservation->expires_at && $reservation->expires_at->isPast())) {
                throw ValidationException::withMessages(['reservation' => 'Only an active reservation can be committed.']);
            }
            $level->decrement('on_hand', $reservation->quantity);
            $level->decrement('reserved', $reservation->quantity);
            $reservation->update(['status' => StockReservationStatus::COMMITTED, 'committed_at' => now()]);
            StockMovement::query()->create(['variant_id' => $reservation->variant_id, 'source_type' => $reservation->source_type, 'source_id' => $reservation->source_id, 'reason' => 'commit', 'on_hand_delta' => -$reservation->quantity, 'reserved_delta' => -$reservation->quantity]);

            return $reservation->refresh();
        });
    }
}
