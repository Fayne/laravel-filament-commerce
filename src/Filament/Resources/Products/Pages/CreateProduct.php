<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources\Products\Pages;

use Fayne\Commerce\Catalog\Actions\SaveProduct;
use Fayne\Commerce\Catalog\Models\Product;
use Fayne\Commerce\Filament\Resources\ProductResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function handleRecordCreation(array $data): Product
    {
        return app(SaveProduct::class)->execute(null, $data);
    }
}
