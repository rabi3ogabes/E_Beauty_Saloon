<?php

namespace App\Filament\Dashboard\Resources\PaymentGateways\Pages;

use App\Domain\Workspace\Actions\SavePaymentGateway;
use App\Filament\Dashboard\Resources\PaymentGateways\PaymentGatewayResource;
use App\Support\Exceptions\DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePaymentGateway extends CreateRecord
{
    protected static string $resource = PaymentGatewayResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(SavePaymentGateway::class)->handle(null, $data);
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return PaymentGatewayResource::getUrl('edit', ['record' => $this->record]);
    }
}
