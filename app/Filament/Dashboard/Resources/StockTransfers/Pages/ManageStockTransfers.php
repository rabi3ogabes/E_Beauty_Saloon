<?php

namespace App\Filament\Dashboard\Resources\StockTransfers\Pages;

use App\Domain\Inventory\Actions\TransferStock;
use App\Filament\Dashboard\Resources\StockTransfers\StockTransferResource;
use App\Support\Exceptions\DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageStockTransfers extends ManageRecords
{
    protected static string $resource = StockTransferResource::class;

    public function getSubheading(): ?string
    {
        return __('workspace.transfers.subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newTransfer')->label(__('workspace.transfers.add'))->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                ->visible(fn () => StockTransferResource::canManage())
                ->modalHeading(__('workspace.transfers.add'))
                ->modalDescription(__('workspace.transfers.atomic_help'))
                ->schema(StockTransferResource::formFields())
                ->action(function (array $data, Action $action) {
                    abort_if(tenant()->isReadOnly(), 423);
                    $lines = collect($data['lines'] ?? [])->mapWithKeys(fn ($l) => [(int) $l['product_variant_id'] => (int) $l['qty']])->all();
                    try {
                        $transfer = app(TransferStock::class)->handle((int) $data['from_branch_id'], (int) $data['to_branch_id'], $lines, $data['notes'] ?? null);
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title(__('workspace.transfers.failed'))->body($e->getMessage())->send();
                        $action->halt();

                        return;
                    }
                    Notification::make()->success()->title(__('workspace.transfers.done', ['number' => $transfer->number]))->send();
                }),
        ];
    }
}
