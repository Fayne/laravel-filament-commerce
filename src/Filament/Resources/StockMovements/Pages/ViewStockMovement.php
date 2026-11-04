<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources\StockMovements\Pages;

use Fayne\Commerce\Filament\Resources\StockMovementResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewStockMovement extends ViewRecord
{
    protected static string $resource = StockMovementResource::class;
}
