<?php

namespace App\Domain\Websites\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class SitePage extends Model
{
    use BelongsToTenant, HasTranslations;

    protected $guarded = ['id'];

    public array $translatable = ['title', 'seo_title', 'seo_description'];

    protected function casts(): array
    {
        return ['is_home' => 'boolean', 'is_published' => 'boolean', 'in_menu' => 'boolean'];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(SiteSection::class)->orderBy('sort')->orderBy('id');
    }

    public function visibleSections(): HasMany
    {
        return $this->sections()->where('is_visible', true);
    }
}
