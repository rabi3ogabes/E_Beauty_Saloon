<?php

namespace App\Filament\Admin\Resources\PlatformPages\Pages;

use App\Domain\Websites\Models\PlatformPage;
use App\Domain\Websites\Support\PlatformContent;
use App\Filament\Admin\Resources\PlatformPages\PlatformPageResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlatformPages extends ListRecords
{
    protected static string $resource = PlatformPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sitemap')->label(__('website.admin.sitemap'))->url(url('/sitemap.xml'), shouldOpenInNewTab: true)->color('gray'),
            // Copy the built-in defaults into editable CMS pages (idempotent).
            Action::make('import')->label(__('website.dashboard.reset_defaults'))->color('gray')->requiresConfirmation()
                ->visible(fn () => PlatformPage::count() < count(PlatformContent::defaults()))
                ->action(function () {
                    $i = 0;
                    foreach (PlatformContent::defaults() as $slug => $page) {
                        PlatformPage::firstOrCreate(['slug' => $slug], ['title' => $page['title'], 'sections' => $page['sections'], 'sort' => $i++]);
                    }
                }),
            CreateAction::make(),
        ];
    }
}
