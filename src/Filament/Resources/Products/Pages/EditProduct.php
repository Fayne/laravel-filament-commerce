<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources\Products\Pages;

use Fayne\Commerce\Catalog\Actions\SaveProduct;
use Fayne\Commerce\Catalog\Models\Product;
use Fayne\Commerce\Filament\Resources\ProductResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $product = $this->record->load('translations');
        foreach ($product->translations as $translation) {
            $data['translations'][$translation->locale] = $translation->only(['name', 'description', 'seo_title', 'seo_description']);
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Product
    {
        return app(SaveProduct::class)->execute($record, $data);
    }
}
