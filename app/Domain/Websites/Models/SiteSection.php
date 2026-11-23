<?php

namespace App\Domain\Websites\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One block of a salon page. content = per-locale copy, settings = shared options (images, limits...). */
class SiteSection extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'array', 'settings' => 'array', 'is_visible' => 'boolean'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(SitePage::class, 'site_page_id');
    }

    /** Localized copy with English fallback, then the section type default. */
    public function t(string $key, ?string $default = null): ?string
    {
        $locale = app()->getLocale();
        $value = data_get($this->content, $locale.'.'.$key);
        if (blank($value)) {
            $value = data_get($this->content, 'en.'.$key);
        }

        return filled($value) ? (string) $value : $default;
    }

    /** Localized list (e.g. FAQ items) with English fallback. */
    public function list(string $key): array
    {
        $items = data_get($this->content, app()->getLocale().'.'.$key);

        return array_values(array_filter((array) (filled($items) ? $items : data_get($this->content, 'en.'.$key, []))));
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
