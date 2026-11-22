<?php

namespace App\Filament\Dashboard\Resources\Marketing\Automations\Pages;

use App\Domain\Marketing\Actions\InstallAutomationRecipes;
use App\Domain\Marketing\Models\Automation;
use App\Filament\Dashboard\Resources\Marketing\Automations\AutomationResource;
use App\Filament\Marketing\MarketingAccess;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAutomations extends ListRecords
{
    protected static string $resource = AutomationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('install')->label(__('marketing.automations.install'))->icon('heroicon-o-sparkles')->color('gray')
                ->visible(fn () => MarketingAccess::manage())
                ->action(function () {
                    $count = app(InstallAutomationRecipes::class)->handle();
                    Notification::make()->success()->title(__('marketing.automations.installed', ['count' => $count]))->send();
                }),
            CreateAction::make()->slideOver()
                ->mutateDataUsing(fn (array $data) => $data + ['steps' => [['type' => 'send_message', 'channels' => ['whatsapp', 'sms'], 'body' => ['en' => '', 'ar' => '']]], 'is_active' => false])
                ->successRedirectUrl(fn (Automation $record) => AutomationResource::getUrl('flow', ['record' => $record])),
        ];
    }
}
