<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\Catalog\Templates\ServiceTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Onboarding quick-import: creates categories + services from the bilingual template catalog (idempotent by slug). */
class ImportServiceTemplates
{
    /** @return int number of services created */
    public function handle(array $categoryKeys): int
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        $created = 0;

        DB::transaction(function () use ($categoryKeys, &$created) {
            $sort = (int) ServiceCategory::max('sort');
            foreach (array_intersect(ServiceTemplates::keys(), $categoryKeys) as $key) {
                $meta = ServiceTemplates::CATEGORIES[$key];
                $category = ServiceCategory::firstOrCreate(['slug' => $key], [
                    'name' => ['en' => $meta['en'], 'ar' => $meta['ar']], 'icon' => $meta['icon'], 'sort' => ++$sort, 'is_active' => true,
                ]);

                foreach (ServiceTemplates::all()[$key] as $i => $tpl) {
                    $slug = Str::slug($tpl['en']);
                    if (Service::withTrashed()->where('slug', $slug)->exists()) {
                        continue;
                    }
                    Service::create([
                        'service_category_id' => $category->id,
                        'name' => ['en' => $tpl['en'], 'ar' => $tpl['ar']],
                        'slug' => $slug,
                        'duration_minutes' => $tpl['duration'],
                        'price' => $tpl['price'],
                        'gender' => 'women',
                        'online_bookable' => true,
                        'deposit_type' => isset($tpl['deposit']) ? 'percent' : null,
                        'deposit_value' => $tpl['deposit'] ?? 0, // basis points
                        'resource_types' => $tpl['resources'] ?? null,
                        'rebook_after_days' => $tpl['rebook'] ?? null,
                        'is_active' => true,
                        'sort' => $i,
                    ]);
                    $created++;
                }
            }
        });

        return $created;
    }
}
