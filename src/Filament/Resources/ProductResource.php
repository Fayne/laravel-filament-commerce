<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources;

use Fayne\CMS\Support\ContentLocale;
use Fayne\Commerce\Catalog\Models\Product;
use Fayne\Commerce\Filament\Resources\Products\Pages\CreateProduct;
use Fayne\Commerce\Filament\Resources\Products\Pages\EditProduct;
use Fayne\Commerce\Filament\Resources\Products\Pages\ListProducts;
use Fayne\Commerce\Filament\Resources\Products\Pages\ViewProduct;
use Fayne\Commerce\Filament\Resources\Products\Schemas\ProductForm;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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

    public static function getModelLabel(): string
    {
        return __('commerce::products.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('commerce::products.plural');
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
            TextColumn::make('type')->label(__('commerce::products.type_label'))->formatStateUsing(fn ($state): string => __('commerce::products.types.'.($state instanceof \BackedEnum ? $state->value : $state)))->badge(),
            TextColumn::make('status')->label(__('commerce::products.status_label'))->formatStateUsing(fn (string $state): string => __('commerce::products.status.'.$state))->badge(),
            TextColumn::make('created_at')->label(__('commerce::products.created_at'))->dateTime(),
        ])->recordActions([ViewAction::make(), EditAction::make(), DeleteAction::make()])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
