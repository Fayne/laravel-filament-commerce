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
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
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

    public static function getModelLabel(): string
    {
        return __('commerce::orders.singular');
    }

    public static function getPluralModelLabel(): string
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
            TextColumn::make('status')->label(__('commerce::orders.status_label'))->formatStateUsing(fn (string $state): string => __('commerce::orders.status.'.$state))->badge(),
            TextColumn::make('payment_status')->label(__('commerce::orders.payment_status_label'))->formatStateUsing(fn (string $state): string => __('commerce::orders.payment_status.'.$state))->badge(),
            TextColumn::make('fulfillment_status')->label(__('commerce::orders.fulfillment'))->state(fn (Order $record): string => __('commerce::orders.fulfillment_status.'.$record->fulfillmentStatus()))->badge(),
            TextColumn::make('payable_amount')->label(__('commerce::orders.payable'))->numeric(),
            TextColumn::make('currency')->label(__('commerce::orders.currency')),
            TextColumn::make('created_at')->label(__('commerce::orders.created_at'))->dateTime()->sortable(),
        ])->filters([
            Filter::make('order_number')->label(__('commerce::orders.number'))->schema([TextInput::make('number')->label(__('commerce::orders.number'))->placeholder(__('commerce::orders.search_order_number'))])->query(fn (Builder $query, array $data): Builder => filled($data['number'] ?? null) ? $query->whereKey(trim($data['number'])) : $query),
            SelectFilter::make('status')->label(__('commerce::orders.status_label'))->options(collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $status): array => [$status->value => __('commerce::orders.status.'.$status->value)])),
        ])->recordActions([
            ViewAction::make(),
            ActionGroup::make([
                Action::make('request_refund')
                    ->label(__('commerce::orders.request_refund'))
                    ->visible(fn (Order $record): bool => $record->payment_status === 'paid' && Gate::allows('requestRefund', $record))
                    ->schema(fn (Order $record): array => [
                        Repeater::make('lines')->label(__('commerce::orders.lines'))->schema([
                            Select::make('order_line_id')->label(__('commerce::orders.order_line'))->options($record->lines->filter(fn ($line): bool => $line->payable_amount > 0)->mapWithKeys(fn ($line): array => [$line->id => $line->sku.' · '.$line->product_name.' · '.__('commerce::orders.quantity').': '.$line->quantity])->all())->required(),
                            TextInput::make('quantity')->label(__('commerce::orders.quantity'))->numeric()->integer()->minValue(1)->required(),
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
                        Repeater::make('lines')->label(__('commerce::orders.lines'))->schema([
                            Select::make('order_line_id')->label(__('commerce::orders.order_line'))->options($record->lines->filter(fn ($line): bool => $line->product_type === ProductType::PHYSICAL->value && $record->shippedQuantityForLine((int) $line->id) < $line->quantity)->mapWithKeys(fn ($line): array => [$line->id => $line->sku.' · '.$line->product_name.' · '.__('commerce::orders.remaining').': '.($line->quantity - $record->shippedQuantityForLine((int) $line->id))])->all())->required(),
                            TextInput::make('quantity')->label(__('commerce::orders.quantity'))->numeric()->integer()->minValue(1)->required(),
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
            ])->label(__('commerce::orders.more_actions'))->icon('heroicon-m-ellipsis-horizontal')->iconButton()->tooltip(__('commerce::orders.more_actions'))->dropdownPlacement('bottom-end'),
        ])->defaultSort('created_at', 'desc')->toolbarActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('order-details')->columnSpanFull()->tabs([
                Tab::make(__('commerce::orders.overview'))->columns(['default' => 1, 'md' => 2])->schema([
                    TextEntry::make('id')->label(__('commerce::orders.number')),
                    TextEntry::make('status')->label(__('commerce::orders.status_label'))->formatStateUsing(fn (string $state): string => __('commerce::orders.status.'.$state))->badge(),
                    TextEntry::make('payment_status')->label(__('commerce::orders.payment_status_label'))->formatStateUsing(fn (string $state): string => __('commerce::orders.payment_status.'.$state))->badge(),
                    TextEntry::make('fulfillment_status')->label(__('commerce::orders.fulfillment'))->state(fn (Order $record): string => __('commerce::orders.fulfillment_status.'.$record->fulfillmentStatus()))->badge(),
                    TextEntry::make('payment_reference')->label(__('commerce::orders.payment_reference')),
                    TextEntry::make('currency')->label(__('commerce::orders.currency')),
                    TextEntry::make('subtotal_amount')->label(__('commerce::orders.subtotal')),
                    TextEntry::make('shipping_amount')->label(__('commerce::orders.shipping')),
                    TextEntry::make('discount_amount')->label(__('commerce::orders.discount')),
                    TextEntry::make('payable_amount')->label(__('commerce::orders.payable')),
                    TextEntry::make('cancellation_reason')->label(__('commerce::orders.cancel_reason'))->placeholder('—')->columnSpanFull(),
                ]),
                Tab::make(__('commerce::orders.contacts'))->columns(['default' => 1, 'md' => 2])->schema([
                    TextEntry::make('buyer_snapshot.name')->label(__('commerce::orders.buyer_name'))->placeholder('—'),
                    TextEntry::make('buyer_snapshot.phone')->label(__('commerce::orders.phone'))->placeholder('—'),
                    TextEntry::make('buyer_snapshot.email')->label(__('commerce::orders.email'))->placeholder('—'),
                    RepeatableEntry::make('shipping_details')->label(__('commerce::orders.shipping_address'))->state(function (Order $record): array {
                        $fields = [];
                        foreach (Arr::dot($record->shipping_snapshot ?? []) as $key => $value) {
                            $translation = 'commerce::orders.address_fields.'.$key;
                            $fields[] = [
                                'field' => Lang::has($translation) ? __($translation) : Str::headline(str_replace('.', ' ', $key)),
                                'value' => is_bool($value) ? __('commerce::orders.boolean.'.($value ? 'yes' : 'no')) : $value,
                            ];
                        }

                        return $fields;
                    })->schema([
                        TextEntry::make('field')->hiddenLabel(),
                        TextEntry::make('value')->hiddenLabel()->placeholder('—'),
                    ])->columns(2)->columnSpanFull()->placeholder(__('commerce::orders.no_address')),
                ]),
                Tab::make(__('commerce::orders.lines'))->schema([
                    RepeatableEntry::make('lines')->label(__('commerce::orders.lines'))->schema([
                        TextEntry::make('sku')->label(__('commerce::products.sku')),
                        TextEntry::make('product_name')->label(__('commerce::products.name')),
                        TextEntry::make('product_type')->label(__('commerce::products.type_label'))->formatStateUsing(fn ($state): string => __('commerce::products.types.'.$state)),
                        TextEntry::make('quantity')->label(__('commerce::orders.quantity')),
                        TextEntry::make('unit_amount')->label(__('commerce::products.price_amount')),
                        TextEntry::make('discount_amount')->label(__('commerce::orders.discount')),
                        TextEntry::make('payable_amount')->label(__('commerce::orders.payable')),
                    ])->columns(3)->columnSpanFull(),
                ]),
                Tab::make(__('commerce::orders.shipments'))->schema([
                    RepeatableEntry::make('shipments')->label(__('commerce::orders.shipments'))->schema([
                        TextEntry::make('carrier')->label(__('commerce::orders.carrier')),
                        TextEntry::make('tracking_number')->label(__('commerce::orders.tracking_number')),
                        TextEntry::make('shipped_at')->label(__('commerce::orders.shipped_at'))->dateTime(),
                        RepeatableEntry::make('lines')->label(__('commerce::orders.lines'))->schema([
                            TextEntry::make('order_line_id')->label(__('commerce::orders.order_line')),
                            TextEntry::make('quantity')->label(__('commerce::orders.quantity')),
                        ])->columns(2)->columnSpanFull(),
                    ])->columns(3)->columnSpanFull(),
                ]),
                Tab::make(__('commerce::orders.refunds'))->schema([
                    RepeatableEntry::make('refundRequests')->label(__('commerce::orders.refunds'))->schema([
                        TextEntry::make('id')->label(__('commerce::orders.refund_number')),
                        TextEntry::make('status')->label(__('commerce::orders.status_label'))->formatStateUsing(fn ($state): string => __('commerce::orders.refund_status.'.($state instanceof \BackedEnum ? $state->value : $state))),
                        TextEntry::make('amount')->label(__('commerce::orders.refund_amount')),
                        TextEntry::make('reason')->label(__('commerce::orders.refund_reason')),
                    ])->columns(2)->columnSpanFull(),
                ]),
                Tab::make(__('commerce::orders.deliveries'))->schema([
                    RepeatableEntry::make('deliveryTasks')->label(__('commerce::orders.deliveries'))->schema([
                        TextEntry::make('id')->label(__('commerce::orders.delivery_number')),
                        TextEntry::make('line.sku')->label(__('commerce::products.sku')),
                        TextEntry::make('unit_number')->label(__('commerce::orders.unit')),
                        TextEntry::make('status')->label(__('commerce::orders.status_label'))->formatStateUsing(fn ($state): string => __('commerce::orders.delivery_status.'.($state instanceof \BackedEnum ? $state->value : $state))),
                    ])->columns(2)->columnSpanFull(),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/'), 'view' => ViewOrder::route('/{record}')];
    }
}
