<?php

namespace App\Filament\Admin\Resources\AIJobs\Pages;

use App\Filament\Admin\Pages\AIMarginReport;
use App\Filament\Admin\Resources\AIJobs\AIJobResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListAIJobs extends ListRecords
{
    protected static string $resource = AIJobResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('margin')->label(__('ai_studio.admin.margin'))->url(AIMarginReport::getUrl())->color('gray')];
    }
}
