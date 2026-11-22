<?php

namespace App\Filament\Dashboard\Resources\PaymentGateways\Pages;

use App\Filament\Dashboard\Resources\PaymentGateways\PaymentGatewayResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPaymentGateways extends ListRecords
{
    protected static string $resource = PaymentGatewayResource::class;

    public function getSubheading(): ?string
    {
        return __('workspace.payments.direct_copy');
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('workspace.payments.add'))->icon(Heroicon::OutlinedPlus)];
    }
}
