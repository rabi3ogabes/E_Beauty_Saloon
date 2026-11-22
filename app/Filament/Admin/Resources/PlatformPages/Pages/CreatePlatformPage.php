<?php

namespace App\Filament\Admin\Resources\PlatformPages\Pages;

use App\Filament\Admin\Resources\PlatformPages\PlatformPageResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlatformPage extends CreateRecord
{
    protected static string $resource = PlatformPageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['sections'])) {
            $data['sections'] = PlatformPageResource::defaultSections((string) $data['slug']);
        }

        return $data;
    }
}
