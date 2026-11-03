<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources\Orders\Pages;

use Fayne\Commerce\Filament\Resources\OrderResource;
use Filament\Resources\Pages\ListRecords;

final class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;
}
