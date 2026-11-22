<?php

namespace App\Domain\AIStudio\Models;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Service;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/** Owner-curated look in the style library, mapped to a real service (+ optional shop shades). */
class Style extends Model
{
    use BelongsToTenant, HasTranslations;

    protected $table = 'style_library';

    protected $guarded = ['id'];

    public array $translatable = ['name', 'description'];

    /** Categories, their default task, camera framing and options. */
    public const CATEGORIES = [
        'hair' => ['task' => 'hair_tryon', 'framing' => 'face', 'options' => ['cut', 'length', 'color', 'highlights', 'balayage', 'curls']],
        'eyes' => ['task' => 'eyes_tryon', 'framing' => 'face', 'options' => ['lashes', 'brows', 'eyeshadow', 'liner']],
        'lips' => ['task' => 'lips_tryon', 'framing' => 'face', 'options' => ['shade', 'finish', 'liner']],
        'nails' => ['task' => 'nails_tryon', 'framing' => 'hand', 'options' => ['shape', 'length', 'color', 'art']],
        'pedicure' => ['task' => 'pedicure_tryon', 'framing' => 'foot', 'options' => ['color', 'art']],
        'makeup' => ['task' => 'full_look', 'framing' => 'face', 'options' => ['day', 'soiree', 'glam']],
        'bridal' => ['task' => 'bridal_look', 'framing' => 'face', 'options' => ['classic', 'modern', 'arabic']],
    ];

    /** Categories that edit the face — blocked for minors. */
    public const FACE_CATEGORIES = ['hair', 'eyes', 'lips', 'makeup', 'bridal'];

    protected function casts(): array
    {
        return ['product_variant_ids' => 'array', 'is_active' => 'boolean'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return Collection<int, ProductVariant> */
    public function shades(): Collection
    {
        $ids = array_filter((array) $this->product_variant_ids);

        return $ids ? ProductVariant::query()->with('product')->whereIn('id', $ids)->get() : new Collection;
    }

    public function isFaceFeature(): bool
    {
        return in_array($this->category, self::FACE_CATEGORIES, true);
    }

    public function framing(): string
    {
        return self::CATEGORIES[$this->category]['framing'] ?? 'face';
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->orderBy('sort')->orderBy('id');
    }
}
