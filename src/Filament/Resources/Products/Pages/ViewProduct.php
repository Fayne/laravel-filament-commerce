<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources\Products\Pages;

use Fayne\Commerce\Catalog\Extensions\ProductExtensions;
use Fayne\Commerce\Filament\Resources\ProductResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach ($this->record->translations as $translation) {
            $data['translations'][$translation->locale] = $translation->only(['name', 'description', 'seo_title', 'seo_description']);
        }

        $data['extensions'] = app(ProductExtensions::class)->fill($this->record);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
