<?php

namespace App\Filament\Dashboard\Resources\PurchaseOrders\Pages;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Actions\MarkPurchaseOrderOrdered;
use App\Domain\Inventory\Actions\ReceivePurchaseOrder;
use App\Filament\Dashboard\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Support\Exceptions\DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markOrdered')->label(__('workspace.purchase_orders.mark_ordered'))->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn () => $this->record->status === 'draft' && PurchaseOrderResource::canManage())
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        app(MarkPurchaseOrderOrdered::class)->handle($this->record);
                        Notification::make()->success()->title(__('workspace.purchase_orders.ordered'))->send();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('receive')->label(__('workspace.purchase_orders.receive'))->icon(Heroicon::OutlinedInboxArrowDown)->color('success')
                ->visible(fn () => in_array($this->record->status, ['ordered', 'partially_received'], true) && PurchaseOrderResource::canManage())
                ->modalDescription(__('workspace.purchase_orders.receive_help'))
                ->fillForm(fn () => ['lines' => $this->record->items->mapWithKeys(fn ($i) => [$i->id => max(0, $i->qty - $i->received_qty)])->all()])
                ->schema(fn () => $this->record->items->filter(fn ($i) => $i->received_qty < $i->qty)->map(fn ($i) => TextInput::make('lines.'.$i->id)
                    ->label(ProductVariant::with('product')->find($i->product_variant_id)?->displayName())
                    ->helperText(__('workspace.purchase_orders.outstanding', ['qty' => $i->qty - $i->received_qty]))
                    ->integer()->minValue(0)->maxValue($i->qty - $i->received_qty)->required())->values()->all())
                ->action(function (array $data) {
                    $lines = array_map('intval', $data['lines'] ?? []);
                    app(ReceivePurchaseOrder::class)->handle($this->record, $lines);
                    $this->record->refresh();
                    Notification::make()->success()->title(__('workspace.purchase_orders.received', ['status' => __('workspace.purchase_orders.status.'.$this->record->status)]))->send();
                }),
            Action::make('cancel')->label(__('workspace.purchase_orders.cancel'))->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn () => in_array($this->record->status, ['draft', 'ordered'], true) && PurchaseOrderResource::canManage())
                ->requiresConfirmation()
                ->action(fn () => $this->record->update(['status' => 'cancelled'])),
        ];
    }
}
