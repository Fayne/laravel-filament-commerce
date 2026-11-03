<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources\RefundRequests\Pages;

use Fayne\Commerce\Filament\Resources\RefundRequestResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewRefundRequest extends ViewRecord
{
    protected static string $resource = RefundRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
