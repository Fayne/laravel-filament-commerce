<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources;

use Fayne\CMS\Support\Operations\Operations;
use Fayne\Commerce\Catalog\Models\ProductVariant;
use Fayne\Commerce\Filament\Resources\StockLevels\Pages\ListStockLevels;
use Fayne\Commerce\Inventory\Actions\AdjustStock;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class StockLevelResource extends Resource
{
    protected static ?string $model = ProductVariant::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static bool $shouldCheckPolicyExistence = true;

    public static function getNavigationLabel(): string
    {
        return __('commerce::inventory.stock');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['product.translations', 'stockLevel']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('sku')->label(__('commerce::products.sku'))->searchable(),
            TextColumn::make('product.translations.name')->label(__('commerce::products.name')),
            TextColumn::make('stockLevel.on_hand')->label(__('commerce::inventory.on_hand'))->default(0),
            TextColumn::make('stockLevel.reserved')->label(__('commerce::inventory.reserved'))->default(0),
            TextColumn::make('available')->label(__('commerce::inventory.available'))->state(fn (ProductVariant $record): int => $record->stockLevel?->available() ?? 0),
        ])->recordActions([
            Action::make('adjust_stock')->label(__('commerce::inventory.adjust'))->visible(fn (ProductVariant $record): bool => Gate::allows('adjustStock', $record))->schema([
                TextInput::make('on_hand')->label(__('commerce::inventory.on_hand'))->numeric()->integer()->minValue(0)->required(),
                TextInput::make('reason')->label(__('commerce::inventory.reason'))->required()->maxLength(500),
            ])->fillForm(fn (ProductVariant $record): array => ['on_hand' => $record->stockLevel?->on_hand ?? 0])->action(function (ProductVariant $record, array $data): void {
                Gate::authorize('adjustStock', $record);
                $level = app(AdjustStock::class)->execute($record, (int) $data['on_hand'], $data['reason'], (string) $record->getKey());
                app(Operations::class)->completed('commerce.stock.adjust', ['variant_id' => $record->getKey(), 'on_hand' => $level->on_hand, 'reserved' => $level->reserved, 'reason' => $data['reason']], $record, ['source' => 'filament']);
                Notification::make()->title(__('commerce::inventory.adjusted'))->success()->send();
            }),
        ])->defaultSort('sku')->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockLevels::route('/')];
    }
}
