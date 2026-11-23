<?php

namespace App\Filament\Admin\Resources\PlatformPages\Pages;

use App\Filament\Admin\Resources\PlatformPages\PlatformPageResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlatformPage extends EditRecord
{
    protected static string $resource = PlatformPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view')->label(__('salon.ui.view'))->color('gray')->url(fn () => PlatformPageResource::publicUrl($this->record), shouldOpenInNewTab: true),
            DeleteAction::make(),
        ];
    }
}
