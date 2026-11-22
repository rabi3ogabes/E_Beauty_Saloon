<?php

namespace App\Filament\Dashboard\Resources\Styles\Pages;

use App\Filament\Dashboard\Resources\Styles\StyleResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStyles extends ManageRecords
{
    protected static string $resource = StyleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label(__('ai_studio.title'))->url(url('/studio'), shouldOpenInNewTab: true)->color('gray'),
            CreateAction::make()->visible(StyleResource::canCreate()),
        ];
    }
}
