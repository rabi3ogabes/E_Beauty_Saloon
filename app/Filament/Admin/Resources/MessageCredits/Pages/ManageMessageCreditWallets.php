<?php

namespace App\Filament\Admin\Resources\MessageCredits\Pages;

use App\Filament\Admin\Resources\MessageCredits\MessageCreditWalletResource;
use Filament\Resources\Pages\ManageRecords;

class ManageMessageCreditWallets extends ManageRecords
{
    protected static string $resource = MessageCreditWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [MessageCreditWalletResource::topUpAction('topupTenant')];
    }
}
