<?php

namespace App\Filament\Dashboard\Resources\ServiceCategories\Pages;

use App\Domain\Catalog\Models\ServiceCategory;
use App\Filament\Dashboard\Resources\ServiceCategories\ServiceCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class ManageServiceCategories extends ManageRecords
{
    protected static string $resource = ServiceCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('workspace.service_categories.add'))->icon(Heroicon::OutlinedPlus)->slideOver()
                ->mutateDataUsing(function (array $data) {
                    $slug = Str::slug($data['name']['en'] ?? '');
                    if ($slug && ! ServiceCategory::where('slug', $slug)->exists() && preg_match('/^[a-z0-9]{6}$/', (string) $data['slug'])) {
                        $data['slug'] = $slug;
                    }

                    return $data;
                }),
        ];
    }
}
