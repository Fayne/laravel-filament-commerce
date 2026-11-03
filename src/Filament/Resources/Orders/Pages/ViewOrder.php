<?php

declare(strict_types=1);

namespace Fayne\Commerce\Filament\Resources\Orders\Pages;

use Fayne\CMS\Support\Operations\Operations;
use Fayne\Commerce\Filament\Resources\OrderResource;
use Fayne\Commerce\Fulfillment\Actions\RequestDeliveryRevocation;
use Fayne\Commerce\Fulfillment\Models\DeliveryTask;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

final class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('revokeDelivery')
                ->label(__('commerce::orders.revoke_delivery'))
                ->visible(fn (): bool => Gate::allows('revokeDelivery', $this->record))
                ->requiresConfirmation()
                ->schema([
                    Select::make('task')->label(__('commerce::orders.deliveries'))
                        ->options(fn (): array => $this->record->deliveryTasks()
                            ->where('status', 'succeeded')
                            ->where(fn ($query) => $query->whereNull('revocation_status')->orWhere('revocation_status', 'failed'))
                            ->get()->mapWithKeys(fn (DeliveryTask $task): array => [
                                $task->id => "#{$task->id} · {$task->handler_key} · {$task->unit_number}",
                            ])->all())->required(),
                ])
                ->action(function (array $data): void {
                    Gate::authorize('revokeDelivery', $this->record);
                    $task = $this->record->deliveryTasks()->findOrFail($data['task']);
                    $requested = app(RequestDeliveryRevocation::class)->execute((int) $task->id, retry: $task->revocation_status === 'failed');
                    app(Operations::class)->completed('commerce.delivery.revoke', [
                        'task_id' => $requested->id, 'attempt' => $requested->revocation_attempt,
                    ], $this->record, ['source' => 'filament']);
                }),
        ];
    }
}
