<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources;

use Fayne\CMS\Support\Operations\Operations;
use Fayne\Commerce\Catalog\Enums\ProductType;
use Fayne\Commerce\Filament\Resources\Orders\Pages\ListOrders;
use Fayne\Commerce\Filament\Resources\Orders\Pages\ViewOrder;
use Fayne\Commerce\Fulfillment\Actions\CreateShipment;
use Fayne\Commerce\Orders\Actions\CancelPendingOrder;
use Fayne\Commerce\Orders\Enums\OrderStatus;
use Fayne\Commerce\Orders\Models\Order;
use Fayne\Commerce\Returns\Actions\CreateRefundRequest;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';

    protected static bool $shouldCheckPolicyExistence = true;

    protected static bool $isScopedToTenant = false;

    protected static ?string $slug = 'commerce/orders';

    public static function getNavigationGroup(): ?string
    {
        return __('commerce::orders.commerce_navigation');
    }

    public static function getNavigationLabel(): string
    {
        return __('commerce::orders.navigation');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['lines', 'shipments.lines', 'refundRequests.lines', 'deliveryTasks.line']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label(__('commerce::orders.number'))->searchable(),
            TextColumn::make('buyer_snapshot.name')->label(__('commerce::orders.buyer'))->searchable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('payment_status')->badge(),
            TextColumn::make('fulfillment_status')->label(__('commerce::orders.fulfillment'))->state(fn (Order $record): string => __('commerce::orders.fulfillment_status.'.$record->fulfillmentStatus()))->badge(),
            TextColumn::make('payable_amount')->label(__('commerce::orders.payable'))->numeric(),
            TextColumn::make('currency')->label(__('commerce::orders.currency')),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->filters([
            Filter::make('order_number')->schema([TextInput::make('number')->label(__('commerce::orders.number'))->placeholder(__('commerce::orders.search_order_number'))])->query(fn (Builder $query, array $data): Builder => filled($data['number'] ?? null) ? $query->whereKey(trim($data['number'])) : $query),
            SelectFilter::make('status')->options(collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $status): array => [$status->value => __('commerce::orders.status.'.$status->value)])),
        ])->recordActions([
            ViewAction::make(),
            Action::make('request_refund')
                ->label(__('commerce::orders.request_refund'))
                ->visible(fn (Order $record): bool => $record->payment_status === 'paid' && Gate::allows('requestRefund', $record))
                ->schema(fn (Order $record): array => [
                    Repeater::make('lines')->schema([
                        Select::make('order_line_id')->options($record->lines->filter(fn ($line): bool => $line->payable_amount > 0)->mapWithKeys(fn ($line): array => [$line->id => $line->sku.' · '.$line->product_name.' · '.__('commerce::orders.quantity').': '.$line->quantity])->all())->required(),
                        TextInput::make('quantity')->numeric()->integer()->minValue(1)->required(),
                    ])->minItems(1)->required(),
                    Textarea::make('reason')->label(__('commerce::orders.refund_reason'))->required()->maxLength(500),
                    Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                ])
                ->action(function (Order $record, array $data): void {
                    Gate::authorize('requestRefund', $record);
                    $adminId = Filament::auth()->id();
                    $request = app(CreateRefundRequest::class)->execute($record, $data['lines'], $data['reason'], $data['idempotency_key'], $adminId ? (int) $adminId : null);
                    app(Operations::class)->completed('commerce.order.refund_request', ['refund_request_id' => $request->getKey(), 'amount' => $request->amount], $record, ['source' => 'filament']);
                    Notification::make()->title(__('commerce::orders.refund_requested'))->success()->send();
                }),
            Action::make('ship')
                ->label(__('commerce::orders.create_shipment'))
                ->visible(fn (Order $record): bool => $record->status === OrderStatus::CONFIRMED->value && $record->fulfillmentStatus() !== 'not_required' && $record->fulfillmentStatus() !== 'shipped' && Gate::allows('ship', $record))
                ->schema(fn (Order $record): array => [
                    Repeater::make('lines')->schema([
                        Select::make('order_line_id')->options($record->lines->filter(fn ($line): bool => $line->product_type === ProductType::PHYSICAL->value && $record->shippedQuantityForLine((int) $line->id) < $line->quantity)->mapWithKeys(fn ($line): array => [$line->id => $line->sku.' · '.$line->product_name.' · '.__('commerce::orders.remaining').': '.($line->quantity - $record->shippedQuantityForLine((int) $line->id))])->all())->required(),
                        TextInput::make('quantity')->numeric()->integer()->minValue(1)->required(),
                    ])->minItems(1)->required(),
                    TextInput::make('carrier')->label(__('commerce::orders.carrier'))->maxLength(100),
                    TextInput::make('tracking_number')->label(__('commerce::orders.tracking_number'))->maxLength(255),
                    Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                ])
                ->action(function (Order $record, array $data): void {
                    Gate::authorize('ship', $record);
                    $shipment = app(CreateShipment::class)->execute($record, $data['lines'], $data['carrier'] ?? null, $data['tracking_number'] ?? null, $data['idempotency_key']);
                    app(Operations::class)->completed('commerce.order.ship', ['shipment_id' => $shipment->getKey(), 'lines' => $data['lines']], $record, ['source' => 'filament']);
                    Notification::make()->title(__('commerce::orders.shipment_created'))->success()->send();
                }),
            Action::make('cancel')
                ->label(__('commerce::orders.cancel'))
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Order $record): bool => $record->status === OrderStatus::AWAITING_PAYMENT->value && Gate::allows('cancel', $record))
                ->schema([Textarea::make('reason')->label(__('commerce::orders.cancel_reason'))->required()->maxLength(500)])
                ->action(function (Order $record, array $data): void {
                    Gate::authorize('cancel', $record);
                    $cancelled = app(CancelPendingOrder::class)->execute($record->getKey(), $data['reason']);
                    app(Operations::class)->completed('commerce.order.cancel', ['reason' => $data['reason']], $cancelled, ['source' => 'filament']);
                    Notification::make()->title(__('commerce::orders.cancelled'))->success()->send();
                }),
        ])->defaultSort('created_at', 'desc')->toolbarActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id')->label(__('commerce::orders.number')),
            TextEntry::make('status')->badge(),
            TextEntry::make('payment_status')->badge(),
            TextEntry::make('fulfillment_status')->label(__('commerce::orders.fulfillment'))->state(fn (Order $record): string => __('commerce::orders.fulfillment_status.'.$record->fulfillmentStatus()))->badge(),
            TextEntry::make('payment_reference')->label(__('commerce::orders.payment_reference')),
            TextEntry::make('currency')->label(__('commerce::orders.currency')),
            TextEntry::make('subtotal_amount')->label(__('commerce::orders.subtotal')),
            TextEntry::make('shipping_amount')->label(__('commerce::orders.shipping')),
            TextEntry::make('discount_amount')->label(__('commerce::orders.discount')),
            TextEntry::make('payable_amount')->label(__('commerce::orders.payable')),
            TextEntry::make('buyer_snapshot')->label(__('commerce::orders.buyer'))->state(fn (Order $record): string => json_encode($record->buyer_snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)),
            TextEntry::make('shipping_snapshot')->label(__('commerce::orders.shipping_address'))->state(fn (Order $record): string => json_encode($record->shipping_snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)),
            TextEntry::make('lines')->label(__('commerce::orders.lines'))->state(fn (Order $record): string => json_encode($record->lines->map(fn ($line): array => $line->only(['sku', 'product_name', 'product_type', 'quantity', 'unit_amount', 'discount_amount', 'payable_amount'])), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))->columnSpanFull(),
            TextEntry::make('shipments')->label(__('commerce::orders.shipments'))->state(fn (Order $record): string => json_encode($record->shipmentSummary(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))->columnSpanFull(),
            TextEntry::make('cancellation_reason')->label(__('commerce::orders.cancel_reason'))->columnSpanFull(),
            TextEntry::make('refundRequests')->label(__('commerce::orders.refunds'))->state(fn (Order $record): string => json_encode($record->refundRequests->map(fn ($refund): array => ['id' => $refund->id, 'status' => $refund->status->value, 'amount' => $refund->amount, 'reason' => $refund->reason]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))->columnSpanFull(),
            TextEntry::make('deliveryTasks')->label(__('commerce::orders.deliveries'))->state(fn (Order $record): string => json_encode($record->deliveryTasks->map(fn ($task): array => ['task_id' => $task->id, 'sku' => $task->line?->sku, 'unit' => $task->unit_number, 'status' => __('commerce::orders.delivery_status.'.$task->status->value)]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/'), 'view' => ViewOrder::route('/{record}')];
    }
}
