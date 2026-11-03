<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources;

use Fayne\Commerce\Filament\Resources\StockMovements\Pages\ListStockMovements;
use Fayne\Commerce\Inventory\Models\StockMovement;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static bool $shouldCheckPolicyExistence = true;

    public static function getNavigationLabel(): string
    {
        return __('commerce::inventory.movements');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('variant');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label(__('commerce::inventory.occurred_at'))->dateTime()->sortable(),
            TextColumn::make('variant.sku')->label(__('commerce::products.sku'))->searchable(),
            TextColumn::make('reason')->label(__('commerce::inventory.reason')),
            TextColumn::make('on_hand_delta')->label(__('commerce::inventory.on_hand_delta')),
            TextColumn::make('reserved_delta')->label(__('commerce::inventory.reserved_delta')),
            TextColumn::make('source_type')->label(__('commerce::inventory.source')),
            TextColumn::make('source_id')->label(__('commerce::inventory.source_id')),
        ])->defaultSort('created_at', 'desc')->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }
}
