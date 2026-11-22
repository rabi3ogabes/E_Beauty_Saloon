<?php

namespace App\Domain\Workspace\Excel;

use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use Illuminate\Support\Str;

class ServicesImport extends WorkspaceImport
{
    public static function columns(): array
    {
        return ['name_en', 'name_ar', 'category', 'duration_minutes', 'price', 'sale_price', 'gender', 'online_bookable', 'description_en', 'description_ar'];
    }

    public static function example(): array
    {
        return ['name_en' => 'Signature Blow-dry', 'name_ar' => 'سشوار مميز', 'category' => 'Hair', 'duration_minutes' => 45, 'price' => '120.00',
            'sale_price' => '', 'gender' => 'women', 'online_bookable' => 'yes', 'description_en' => 'Smooth, glossy finish.', 'description_ar' => 'لمسة ناعمة ولامعة.'];
    }

    public static function exportRows(): array
    {
        return Service::query()->with('category')->orderBy('sort')->get()->map(fn (Service $s) => [
            'name_en' => $s->getTranslation('name', 'en', false), 'name_ar' => $s->getTranslation('name', 'ar', false),
            'category' => $s->category?->getTranslation('name', 'en', false), 'duration_minutes' => $s->duration_minutes,
            'price' => static::decimal((int) $s->price), 'sale_price' => static::decimal($s->sale_price), 'gender' => $s->gender,
            'online_bookable' => $s->online_bookable ? 'yes' : 'no',
            'description_en' => $s->getTranslation('description', 'en', false), 'description_ar' => $s->getTranslation('description', 'ar', false),
        ])->all();
    }

    protected function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:720'],
            'price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'lte:price'],
            'gender' => ['nullable', 'in:women,men,kids,unisex'],
        ];
    }

    protected function importRow(array $row): string
    {
        $categoryId = null;
        if (filled($row['category'] ?? null)) {
            $slug = Str::slug($row['category']) ?: Str::lower(Str::random(6));
            $categoryId = ServiceCategory::firstOrCreate(['slug' => $slug], ['name' => ['en' => $row['category']], 'is_active' => true])->id;
        }

        $slug = Str::slug($row['name_en']) ?: Str::lower(Str::random(8));
        $service = Service::withTrashed()->firstOrNew(['slug' => $slug]);
        $exists = $service->exists;

        $service->fill([
            'service_category_id' => $categoryId ?? $service->service_category_id,
            'name' => array_filter(['en' => $row['name_en'], 'ar' => $row['name_ar'] ?? null]),
            'description' => array_filter(['en' => $row['description_en'] ?? null, 'ar' => $row['description_ar'] ?? null]) ?: null,
            'duration_minutes' => (int) $row['duration_minutes'],
            'price' => $this->money($row['price']),
            'sale_price' => $this->money($row['sale_price'] ?? null),
            'gender' => $row['gender'] ?: 'women',
            'online_bookable' => $this->bool($row['online_bookable'] ?? null),
            'is_active' => true,
        ]);
        $service->deleted_at = null;
        $service->save();

        return $exists ? 'updated' : 'created';
    }
}
