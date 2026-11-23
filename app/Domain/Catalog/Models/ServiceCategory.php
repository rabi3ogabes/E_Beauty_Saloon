<?php

namespace App\Domain\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class ServiceCategory extends Model
{
    use BelongsToTenant, HasTranslations;

    protected $table = 'service_categories';

    protected $guarded = ['id'];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
