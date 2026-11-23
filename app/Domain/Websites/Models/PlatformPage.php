<?php

namespace App\Domain\Websites\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/** Platform marketing page managed in /admin. Sections: [{type, data: {en: {...}, ar: {...}, ...shared}}]. */
class PlatformPage extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    public array $translatable = ['title', 'seo_title', 'seo_description'];

    protected function casts(): array
    {
        return ['sections' => 'array', 'is_published' => 'boolean', 'in_footer' => 'boolean'];
    }

    public static function published(string $slug): ?self
    {
        return static::query()->where('slug', $slug)->where('is_published', true)->first();
    }
}
