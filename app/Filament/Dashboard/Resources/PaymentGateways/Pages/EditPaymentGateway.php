<?php

namespace App\Filament\Dashboard\Resources\PaymentGateways\Pages;

use App\Domain\Workspace\Actions\SavePaymentGateway;
use App\Filament\Dashboard\Resources\PaymentGateways\PaymentGatewayResource;
use App\Support\Exceptions\DomainException;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPaymentGateway extends EditRecord
{
    protected static string $resource = PaymentGatewayResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['credentials'], $data['webhook_secret']); // secrets are never sent to the browser

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(SavePaymentGateway::class)->handle($record, $data);
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }

    protected function afterSave(): void
    {
        $this->data['credentials'] = []; // clear "replace" inputs after saving
    }

    protected function getHeaderActions(): array
    {
        return [
            PaymentGatewayResource::testAction()->record($this->record),
            DeleteAction::make(),
        ];
    }
}
