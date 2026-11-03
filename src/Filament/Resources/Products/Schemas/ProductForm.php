<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources\Products\Schemas;

use Fayne\CMS\Support\ContentLocale;
use Fayne\Commerce\Catalog\Enums\ProductType;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        $locales = ContentLocale::enabled() ? ContentLocale::supported() : [ContentLocale::default() => ContentLocale::default()];
        $localeFields = [];
        foreach ($locales as $locale => $label) {
            $localeFields[] = Section::make($label)->schema([
                TextInput::make("translations.{$locale}.name")->label(__('commerce::products.name'))->required($locale === ContentLocale::default())->maxLength(255),
                Textarea::make("translations.{$locale}.description")->label(__('commerce::products.description')),
                TextInput::make("translations.{$locale}.seo_title")->label(__('commerce::products.seo_title'))->maxLength(255),
                TextInput::make("translations.{$locale}.seo_description")->label(__('commerce::products.seo_description')),
            ])->columns(2);
        }

        return $schema->components([
            Section::make(__('commerce::products.details'))->schema([
                Select::make('type')->options(collect(ProductType::cases())->mapWithKeys(fn (ProductType $type): array => [$type->value => __('commerce::products.types.'.$type->value)]))->required()->live(),
                Select::make('status')->options(['draft' => __('commerce::products.status.draft'), 'active' => __('commerce::products.status.active'), 'archived' => __('commerce::products.status.archived')])->default('draft')->required(),
                TextInput::make('delivery_handler')->visible(fn (Get $get): bool => in_array($get('type'), [ProductType::DIGITAL->value, ProductType::SERVICE->value], true)),
                Repeater::make('variants')->relationship()->schema([
                    TextInput::make('sku')->required()->maxLength(128),
                    KeyValue::make('option_values')->keyLabel(__('commerce::products.option'))->valueLabel(__('commerce::products.value')),
                    TextInput::make('price_amount')->numeric()->integer()->minValue(0)->required()->helperText(__('commerce::products.price_help')),
                    Toggle::make('is_active')->default(true),
                ])->columns(4)->minItems(1),
            ])->columns(2),
            ...$localeFields,
        ]);
    }
}
