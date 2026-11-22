<?php

namespace App\Filament\Dashboard\Resources\SitePages\Pages;

use App\Domain\Websites\Actions\EnsureHomePage;
use App\Domain\Websites\Models\SitePage;
use App\Filament\Dashboard\Resources\SitePages\SitePageResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSitePages extends ListRecords
{
    protected static string $resource = SitePageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('home')->label(__('website.dashboard.create_home'))->icon('heroicon-o-sparkles')->color('gray')
                ->visible(fn () => SitePage::where('is_home', true)->doesntExist())
                ->action(fn () => $this->redirect(SitePageResource::getUrl('edit', ['record' => app(EnsureHomePage::class)->handle(tenant())]))),
            Action::make('preview')->label(__('website.dashboard.preview'))->url(url('/'), shouldOpenInNewTab: true)->color('gray'),
            CreateAction::make(),
        ];
    }
}
