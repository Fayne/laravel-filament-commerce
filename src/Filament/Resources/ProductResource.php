<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources;

use Fayne\CMS\Support\ContentLocale;
use Fayne\Commerce\Catalog\Models\Product;
use Fayne\Commerce\Filament\Resources\Products\Pages\CreateProduct;
use Fayne\Commerce\Filament\Resources\Products\Pages\EditProduct;
use Fayne\Commerce\Filament\Resources\Products\Pages\ListProducts;
use Fayne\Commerce\Filament\Resources\Products\Schemas\ProductForm;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static bool $shouldCheckPolicyExistence = true;

    protected static bool $isScopedToTenant = false;

    protected static ?string $slug = 'commerce/products';

    public static function getNavigationGroup(): ?string
    {
        return __('commerce::orders.commerce_navigation');
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('translations');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('commerce::products.name'))->state(fn (Product $record): ?string => $record->translations->firstWhere('locale', ContentLocale::default())?->name),
            TextColumn::make('type')->badge(),
            TextColumn::make('status')->badge(),
            TextColumn::make('created_at')->dateTime(),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
