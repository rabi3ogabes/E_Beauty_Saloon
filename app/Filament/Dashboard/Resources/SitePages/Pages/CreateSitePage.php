<?php

namespace App\Filament\Dashboard\Resources\SitePages\Pages;

use App\Filament\Dashboard\Resources\SitePages\SitePageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSitePage extends CreateRecord
{
    protected static string $resource = SitePageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        abort_if(tenant()->isReadOnly(), 423);
        $data['is_home'] = false;

        return $data;
    }
}
