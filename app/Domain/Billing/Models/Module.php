<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class Module extends Model
{
    use HasTranslations;

    protected $table = 'modules';

    protected $guarded = ['id'];

    public array $translatable = ['name', 'description'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ModulePrice::class);
    }

    public function priceFor(?Plan $plan): ?ModulePrice
    {
        return $this->prices->firstWhere('plan_id', $plan?->id) ?? $this->prices->firstWhere('plan_id', null);
    }
}
