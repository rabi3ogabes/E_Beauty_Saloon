<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\AIStudio\Models\Style;
use App\Domain\AIStudio\Support\ExampleLooks;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Installs the bundled example looks (photos + hair-extension services) into one salon. Idempotent:
 * looks are matched by category + English name, and a look that already has photos keeps them —
 * a salon's own work is never replaced unless $force is set.
 *
 * @return array{created: int, photographed: int, services: int}
 */
class InstallExampleLooks
{
    public function handle(Tenant $tenant, bool $force = false): array
    {
        return app(TenantContext::class)->run($tenant, fn () => DB::transaction(function () use ($tenant, $force) {
            $stats = ['created' => 0, 'photographed' => 0, 'services' => $this->ensureExtensionServices()];
            $disk = Storage::disk('public');
            $existing = Style::query()->get();
            $nextSort = (int) $existing->max('sort') + 1;

            foreach (ExampleLooks::all() as $key => [$category, $option, $en, $ar, $descEn, $descAr, $prompt, $serviceSlug, $swatch]) {
                $style = $existing->first(fn (Style $s) => $s->category === $category && $s->getTranslation('name', 'en', false) === $en);
                $service = Service::query()->where('slug', $serviceSlug)->first();

                if (! $style) {
                    $style = Style::create([
                        'category' => $category, 'option' => $option, 'name' => ['en' => $en, 'ar' => $ar],
                        'description' => ['en' => $descEn, 'ar' => $descAr], 'prompt' => $prompt,
                        'task' => Style::CATEGORIES[$category]['task'], 'swatch' => $swatch,
                        'service_id' => $service?->id, 'sort' => $nextSort++, 'is_active' => true,
                    ]);
                    $stats['created']++;
                } else {
                    $style->forceFill(array_filter([
                        'service_id' => $style->service_id ?: $service?->id,
                        // The demo seed's placeholder text gets the richer description.
                        'description' => str_starts_with((string) $style->getTranslation('description', 'en', false), 'Curated by our artists') ? ['en' => $descEn, 'ar' => $descAr] : null,
                    ]))->save();
                }

                if ($style->imagePaths() !== [] && ! $force) {
                    continue; // the salon's own photos win
                }

                $paths = [];
                foreach (ExampleLooks::files($key) as $file) {
                    $source = base_path(ExampleLooks::SOURCE_DIR.'/'.$file);
                    if (! is_file($source)) {
                        continue;
                    }
                    $target = ExampleLooks::TARGET_DIR.'/'.$tenant->id.'/'.$file;
                    $disk->put($target, file_get_contents($source));
                    $thumb = preg_replace('/\.webp$/', '-thumb.webp', $file);
                    if (is_file($thumbSource = base_path(ExampleLooks::SOURCE_DIR.'/'.$thumb))) {
                        $disk->put(ExampleLooks::TARGET_DIR.'/'.$tenant->id.'/'.$thumb, file_get_contents($thumbSource));
                    }
                    $paths[] = $target;
                }
                if ($paths) {
                    $style->forceFill(['reference_path' => array_shift($paths), 'gallery' => $paths, 'illustrative' => true])->save();
                    $stats['photographed']++;
                }
            }

            return $stats;
        }));
    }

    /** Hair-extension services for the new looks, bookable with the salon's hair team. */
    protected function ensureExtensionServices(): int
    {
        $hair = ServiceCategory::query()->where('slug', 'hair')->first();
        if (! $hair) {
            return 0;
        }
        $template = Service::query()->whereIn('slug', ['balayage', 'precision-cut-style'])->with(['staff', 'branches'])->first();
        $created = 0;
        foreach (ExampleLooks::EXTENSION_SERVICES as $i => [$slug, $en, $ar, $minutes, $price]) {
            if (Service::query()->where('slug', $slug)->exists()) {
                continue;
            }
            $service = Service::create([
                'service_category_id' => $hair->id, 'name' => ['en' => $en, 'ar' => $ar], 'slug' => $slug,
                'description' => ['en' => "{$en} — colour-matched and fitted by our extension specialists.", 'ar' => "{$ar} — بلون مطابق وتركيب على يد خبيرات الوصلات."],
                'duration_minutes' => $minutes, 'buffer_after' => $minutes >= 90 ? 15 : 5, 'price' => $price, 'gender' => 'women',
                'deposit_type' => $price >= 90000 ? 'percent' : null, 'deposit_value' => $price >= 90000 ? 3000 : 0,
                'resource_types' => ['chair'], 'rebook_after_days' => $slug === 'clip-in-extensions' ? null : 42, 'sort' => 50 + $i,
            ]);
            $branches = $template?->branches->isNotEmpty() ? $template->branches : Branch::query()->get();
            $service->branches()->attach($branches->pluck('id')->mapWithKeys(fn ($id) => [$id => ['is_active' => true]])->all());
            if ($template) {
                $service->staff()->attach($template->staff->pluck('id')->all());
            }
            $created++;
        }

        return $created;
    }
}
