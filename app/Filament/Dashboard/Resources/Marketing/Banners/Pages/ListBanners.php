<?php

namespace App\Filament\Dashboard\Resources\Marketing\Banners\Pages;

use App\Filament\Dashboard\Resources\Marketing\Banners\BannerResource;
use App\Filament\Marketing\MarketingAccess;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListBanners extends ListRecords
{
    protected static string $resource = BannerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')->label(__('marketing.banners.new'))->icon('heroicon-o-plus')
                ->url(BannerResource::getUrl('create'))->visible(fn () => MarketingAccess::manage()),
        ];
    }
}
