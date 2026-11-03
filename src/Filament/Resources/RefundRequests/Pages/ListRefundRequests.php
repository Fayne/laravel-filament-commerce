<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources\RefundRequests\Pages;

use Fayne\Commerce\Filament\Resources\RefundRequestResource;
use Filament\Resources\Pages\ListRecords;

final class ListRefundRequests extends ListRecords
{
    protected static string $resource = RefundRequestResource::class;
}
