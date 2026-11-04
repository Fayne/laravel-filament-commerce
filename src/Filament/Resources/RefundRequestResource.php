<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources;

use Fayne\CMS\Support\Operations\Operations;
use Fayne\Commerce\Filament\Resources\RefundRequests\Pages\ListRefundRequests;
use Fayne\Commerce\Filament\Resources\RefundRequests\Pages\ViewRefundRequest;
use Fayne\Commerce\Returns\Actions\ApproveRefundRequest;
use Fayne\Commerce\Returns\Actions\RejectRefundRequest;
use Fayne\Commerce\Returns\Enums\RefundStatus;
use Fayne\Commerce\Returns\Models\RefundRequest;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class RefundRequestResource extends Resource
{
    protected static ?string $model = RefundRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static bool $shouldCheckPolicyExistence = true;

    protected static bool $isScopedToTenant = false;

    protected static ?string $slug = 'commerce/refund-requests';

    public static function getNavigationGroup(): ?string
    {
        return __('commerce::orders.commerce_navigation');
    }

    public static function getNavigationLabel(): string
    {
        return __('commerce::orders.refunds');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('lines');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label(__('commerce::orders.refund_number'))->searchable(),
            TextColumn::make('order_id')->label(__('commerce::orders.number'))->searchable(),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state): string => __('commerce::orders.refund_status.'.($state instanceof RefundStatus ? $state->value : $state))),
            TextColumn::make('amount')->label(__('commerce::orders.refund_amount'))->numeric(),
            TextColumn::make('currency'),
            TextColumn::make('reason')->limit(60),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->options(collect(RefundStatus::cases())->mapWithKeys(fn (RefundStatus $status): array => [$status->value => __('commerce::orders.refund_status.'.$status->value)])),
        ])->recordActions([
            ViewAction::make(),
            Action::make('approve')
                ->label(__('commerce::orders.approve_refund'))
                ->color('success')->requiresConfirmation()
                ->visible(fn (RefundRequest $record): bool => $record->status === RefundStatus::PENDING_REVIEW && Gate::allows('review', $record))
                ->action(function (RefundRequest $record): void {
                    Gate::authorize('review', $record);
                    $adminId = Filament::auth()->id();
                    $approved = app(ApproveRefundRequest::class)->execute((string) $record->getKey(), $adminId ? (int) $adminId : null);
                    app(Operations::class)->completed('commerce.refund.approve', ['refund_request_id' => $approved->getKey(), 'amount' => $approved->amount], $approved, ['source' => 'filament']);
                    Notification::make()->title(__('commerce::orders.refund_approved'))->success()->send();
                }),
            Action::make('reject')
                ->label(__('commerce::orders.reject_refund'))
                ->color('danger')->requiresConfirmation()
                ->visible(fn (RefundRequest $record): bool => $record->status === RefundStatus::PENDING_REVIEW && Gate::allows('review', $record))
                ->schema([Textarea::make('reason')->label(__('commerce::orders.rejection_reason'))->required()->maxLength(500)])
                ->action(function (RefundRequest $record, array $data): void {
                    Gate::authorize('review', $record);
                    $adminId = Filament::auth()->id();
                    $rejected = app(RejectRefundRequest::class)->execute((string) $record->getKey(), $data['reason'], $adminId ? (int) $adminId : null);
                    app(Operations::class)->completed('commerce.refund.reject', ['refund_request_id' => $rejected->getKey(), 'reason' => $data['reason']], $rejected, ['source' => 'filament']);
                    Notification::make()->title(__('commerce::orders.refund_rejected'))->success()->send();
                }),
        ])->defaultSort('created_at', 'desc')->toolbarActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id')->label(__('commerce::orders.refund_number')),
            TextEntry::make('order_id')->label(__('commerce::orders.number')),
            TextEntry::make('status')->badge()->formatStateUsing(fn ($state): string => __('commerce::orders.refund_status.'.($state instanceof RefundStatus ? $state->value : $state))),
            TextEntry::make('amount')->label(__('commerce::orders.refund_amount')),
            TextEntry::make('currency'),
            TextEntry::make('reason')->columnSpanFull(),
            TextEntry::make('failure_reason')->label(__('commerce::orders.rejection_reason'))->columnSpanFull(),
            TextEntry::make('lines')->state(fn (RefundRequest $record): string => json_encode($record->lines->map(fn ($line): array => ['order_line_id' => $line->order_line_id, 'quantity' => $line->quantity, 'amount' => $line->amount, 'unit_allocations' => $line->unit_allocations]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListRefundRequests::route('/'), 'view' => ViewRefundRequest::route('/{record}')];
    }
}
