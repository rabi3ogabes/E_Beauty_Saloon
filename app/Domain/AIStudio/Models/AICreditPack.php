<?php

namespace App\Domain\AIStudio\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class AICreditPack extends Model
{
    use HasTranslations;

    protected $table = 'ai_credit_packs';

    protected $guarded = ['id'];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_featured' => 'boolean'];
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->orderBy('sort')->orderBy('credits');
    }
}
