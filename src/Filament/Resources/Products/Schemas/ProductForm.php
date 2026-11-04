<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources\Products\Schemas;

use Fayne\CMS\Support\ContentLocale;
use Fayne\Commerce\Catalog\Enums\ProductType;
use Fayne\Commerce\Catalog\Extensions\ProductExtensions;
use Fayne\Commerce\Catalog\Extensions\SkuExtensions;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        $extensions = app(ProductExtensions::class);
        $locales = ContentLocale::enabled() ? ContentLocale::supported() : [ContentLocale::default() => ContentLocale::default()];
        $localeTabs = [];
        foreach ($locales as $locale => $label) {
            $localeTabs[] = Tab::make($label)->schema([
                TextInput::make("translations.{$locale}.name")->label(__('commerce::products.name'))->required($locale === ContentLocale::default())->maxLength(255)->columnSpanFull(),
                Textarea::make("translations.{$locale}.description")->label(__('commerce::products.description'))->rows(4)->columnSpanFull(),
                TextInput::make("translations.{$locale}.seo_title")->label(__('commerce::products.seo_title'))->maxLength(255),
                Textarea::make("translations.{$locale}.seo_description")->label(__('commerce::products.seo_description'))->rows(3),
            ])->columns(['default' => 1, 'md' => 2]);
        }
        $content = count($localeTabs) > 1
            ? [Tabs::make('product-locales')->tabs($localeTabs)->columnSpanFull()]
            : $localeTabs[0]->getChildComponents();

        return $schema->components([
            Tabs::make('product-editor')->columnSpanFull()->tabs([
                Tab::make(__('commerce::products.details'))->columns(['default' => 1, 'md' => 2])->schema([
                    Select::make('type')->label(__('commerce::products.type_label'))->options(collect(ProductType::cases())->mapWithKeys(fn (ProductType $type): array => [$type->value => __('commerce::products.types.'.$type->value)]))->required()->live(),
                    Select::make('status')->label(__('commerce::products.status_label'))->options(['draft' => __('commerce::products.status.draft'), 'active' => __('commerce::products.status.active'), 'archived' => __('commerce::products.status.archived')])->default('draft')->required(),
                    TextInput::make('delivery_handler')->label(__('commerce::products.delivery_handler'))->columnSpanFull()->visible(fn (Get $get): bool => in_array($get('type'), [ProductType::DIGITAL->value, ProductType::SERVICE->value], true)),
                    ...$extensions->fields('basic'),
                ]),
                Tab::make(__('commerce::products.variants'))->schema([
                    Repeater::make('variants')->hiddenLabel()->relationship()->columnSpanFull()->schema([
                        TextInput::make('sku')->label(__('commerce::products.sku'))->required()->maxLength(128),
                        TextInput::make('price_amount')->label(__('commerce::products.price_amount'))->numeric()->integer()->minValue(0)->required()->helperText(__('commerce::products.price_help')),
                        Toggle::make('is_active')->label(__('commerce::products.is_active'))->default(true),
                        KeyValue::make('option_values')->label(__('commerce::products.options'))->keyLabel(__('commerce::products.option'))->valueLabel(__('commerce::products.value'))->columnSpanFull(),
                        ...app(SkuExtensions::class)->fields(),
                    ])->columns(['default' => 1, 'md' => 2])->minItems(1),
                ]),
                Tab::make(__('commerce::products.content_seo'))->columns(['default' => 1, 'md' => 2])->schema([...$content, ...$extensions->fields('content')]),
                ...$extensions->tabs(),
            ]),
        ]);
    }
}
