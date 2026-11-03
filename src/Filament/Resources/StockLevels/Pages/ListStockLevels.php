<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources\StockLevels\Pages;

use Fayne\Commerce\Filament\Resources\StockLevelResource;
use Fayne\Commerce\Filament\Resources\StockMovementResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListStockLevels extends ListRecords
{
    protected static string $resource = StockLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('movements')->label(__('commerce::inventory.movements'))->url(StockMovementResource::getUrl('index'))];
    }
}
