<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources;

use Fayne\Commerce\Filament\Resources\StockMovements\Pages\ListStockMovements;
use Fayne\Commerce\Filament\Resources\StockMovements\Pages\ViewStockMovement;
use Fayne\Commerce\Inventory\Models\StockMovement;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;

final class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static bool $shouldCheckPolicyExistence = true;

    protected static bool $isScopedToTenant = false;

    protected static ?string $slug = 'commerce/stock-movements';

    public static function getNavigationGroup(): ?string
    {
        return __('commerce::orders.commerce_navigation');
    }

    public static function getNavigationLabel(): string
    {
        return __('commerce::inventory.movements');
    }

    public static function getModelLabel(): string
    {
        return __('commerce::inventory.movement');
    }

    public static function getPluralModelLabel(): string
    {
        return __('commerce::inventory.movements');
    }

    public static function reasonLabel(StockMovement $record): string
    {
        $key = 'commerce::inventory.reasons.'.$record->reason;

        return $record->source_type !== 'adjustment' && Lang::has($key) ? __($key) : $record->reason;
    }

    public static function sourceLabel(?string $source): string
    {
        $key = 'commerce::inventory.sources.'.$source;

        return $source !== null && Lang::has($key) ? __($key) : ($source ?? '—');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id')->label(__('commerce::inventory.number')),
            TextEntry::make('created_at')->label(__('commerce::inventory.occurred_at'))->dateTime(),
            TextEntry::make('variant.sku')->label(__('commerce::products.sku')),
            TextEntry::make('variant_id')->label(__('commerce::inventory.variant_id')),
            TextEntry::make('on_hand_delta')->label(__('commerce::inventory.on_hand_delta')),
            TextEntry::make('reserved_delta')->label(__('commerce::inventory.reserved_delta')),
            TextEntry::make('source_type')->label(__('commerce::inventory.source'))->formatStateUsing(fn ($state) => self::sourceLabel($state)),
            TextEntry::make('source_id')->label(__('commerce::inventory.source_id'))->placeholder('—')->copyable(),
            TextEntry::make('reason')->label(__('commerce::inventory.reason'))->state(fn (StockMovement $record): string => self::reasonLabel($record))->columnSpanFull(),
        ])->columns(['default' => 1, 'md' => 2]);
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
            TextColumn::make('reason')->label(__('commerce::inventory.reason'))->formatStateUsing(fn (StockMovement $record): string => self::reasonLabel($record)),
            TextColumn::make('on_hand_delta')->label(__('commerce::inventory.on_hand_delta')),
            TextColumn::make('reserved_delta')->label(__('commerce::inventory.reserved_delta')),
            TextColumn::make('source_type')->label(__('commerce::inventory.source'))->formatStateUsing(fn ($state) => self::sourceLabel($state)),
            TextColumn::make('source_id')->label(__('commerce::inventory.source_id')),
        ])->recordActions([ViewAction::make()])->defaultSort('created_at', 'desc')->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/'), 'view' => ViewStockMovement::route('/{record}')];
    }
}
