<?php

declare(strict_types=1);

namespace Fayne\Commerce\Inventory\Actions;

use Fayne\Commerce\Catalog\Models\ProductVariant;
use Fayne\Commerce\Inventory\Models\StockLevel;
use Fayne\Commerce\Inventory\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdjustStock
{
    public function execute(ProductVariant $variant, int $newOnHand, string $reason, ?string $sourceId = null): StockLevel
    {
        if ($newOnHand < 0 || trim($reason) === '') {
            throw ValidationException::withMessages(['stock' => 'A non-negative stock quantity and reason are required.']);
        }

        return DB::transaction(function () use ($variant, $newOnHand, $reason, $sourceId): StockLevel {
            $level = StockLevel::query()->where('variant_id', $variant->getKey())->lockForUpdate()->first();
            if (! $level) {
                $level = StockLevel::query()->create(['variant_id' => $variant->getKey(), 'on_hand' => 0, 'reserved' => 0]);
                $level = StockLevel::query()->whereKey($level->getKey())->lockForUpdate()->firstOrFail();
            }
            if ($newOnHand < $level->reserved) {
                throw ValidationException::withMessages(['stock' => 'On-hand stock cannot be lower than reserved stock.']);
            }
            $delta = $newOnHand - $level->on_hand;
            $level->update(['on_hand' => $newOnHand]);
            if ($delta !== 0) {
                StockMovement::query()->create(['variant_id' => $variant->getKey(), 'source_type' => 'adjustment', 'source_id' => $sourceId, 'reason' => $reason, 'on_hand_delta' => $delta, 'reserved_delta' => 0]);
            }

            return $level->refresh();
        });
    }
}
