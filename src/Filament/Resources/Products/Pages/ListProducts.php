<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources\Products\Pages;

use Fayne\Commerce\Filament\Resources\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
