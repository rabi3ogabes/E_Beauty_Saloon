<?php

namespace App\Filament\Dashboard\Resources\SitePages\Pages;

use App\Domain\Websites\Actions\EnsureHomePage;
use App\Filament\Dashboard\Resources\SitePages\SitePageResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSitePage extends EditRecord
{
    protected static string $resource = SitePageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label(__('website.dashboard.preview'))->color('gray')
                ->url(fn () => url($this->record->is_home ? '/' : '/pages/'.$this->record->slug), shouldOpenInNewTab: true),
            Action::make('reset')->label(__('website.dashboard.reset_defaults'))->color('gray')->requiresConfirmation()
                ->visible(fn () => $this->record->is_home)
                ->action(function () {
                    app(EnsureHomePage::class)->handle(tenant(), reset: true);
                    Notification::make()->success()->title(__('website.dashboard.reset_done'))->send();
                    $this->redirect(static::getUrl(['record' => $this->record]));
                }),
            DeleteAction::make()->hidden(fn () => $this->record->is_home),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_if(tenant()->isReadOnly(), 423);

        return $data;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('website.dashboard.saved');
    }
}
