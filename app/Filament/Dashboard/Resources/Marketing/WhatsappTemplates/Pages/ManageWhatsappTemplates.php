<?php

namespace App\Filament\Dashboard\Resources\Marketing\WhatsappTemplates\Pages;

use App\Domain\Marketing\Actions\SyncWhatsAppTemplates;
use App\Filament\Dashboard\Resources\Marketing\WhatsappTemplates\WhatsappTemplateResource;
use App\Filament\Marketing\MarketingAccess;
use App\Support\Exceptions\DomainException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageWhatsappTemplates extends ManageRecords
{
    protected static string $resource = WhatsappTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')->label(__('marketing.templates.sync'))->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn () => MarketingAccess::manage())
                ->action(function () {
                    try {
                        $count = app(SyncWhatsAppTemplates::class)->handle();
                        Notification::make()->success()->title(__('marketing.templates.synced', ['count' => $count]))->send();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            CreateAction::make()->slideOver(),
        ];
    }
}
