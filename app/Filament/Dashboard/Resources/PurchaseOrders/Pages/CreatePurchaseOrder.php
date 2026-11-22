<?php

namespace App\Filament\Dashboard\Resources\PurchaseOrders\Pages;

use App\Domain\Inventory\Actions\CreatePurchaseOrder as CreatePurchaseOrderAction;
use App\Filament\Dashboard\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Support\Exceptions\DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CreatePurchaseOrderAction::class)->handle(
                (int) $data['branch_id'], $data['supplier_id'] ?? null, $data['items'] ?? [], $data['expected_at'] ?? null, $data['notes'] ?? null,
            );
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return PurchaseOrderResource::getUrl('view', ['record' => $this->record]);
    }
}
