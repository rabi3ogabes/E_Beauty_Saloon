<?php

namespace App\Domain\AIStudio\Models;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Service;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
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
        'extensions' => ['task' => 'hair_tryon', 'framing' => 'face', 'options' => ['length', 'volume', 'ponytail']],
        'makeup' => ['task' => 'full_look', 'framing' => 'face', 'options' => ['day', 'soiree', 'glam']],
        'bridal' => ['task' => 'bridal_look', 'framing' => 'face', 'options' => ['classic', 'modern', 'arabic']],
    ];

    /** Categories that edit the face — blocked for minors. */
    public const FACE_CATEGORIES = ['hair', 'extensions', 'eyes', 'lips', 'makeup', 'bridal'];

    protected function casts(): array
    {
        return ['product_variant_ids' => 'array', 'gallery' => 'array', 'illustrative' => 'boolean', 'is_active' => 'boolean'];
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

    /** Every example photo, cover first: the reference image, then the gallery (public disk paths). */
    public function imagePaths(): array
    {
        return array_values(array_unique(array_filter([$this->reference_path, ...(array) $this->gallery])));
    }

    /** @return list<string> public URLs */
    public function imageUrls(): array
    {
        return array_map(fn (string $p) => Storage::disk('public')->url($p), $this->imagePaths());
    }

    /** Small version of the cover for grids: "<name>-thumb.<ext>" next to it when present, else the cover. */
    public function thumbUrl(): ?string
    {
        $cover = $this->imagePaths()[0] ?? null;
        if (! $cover) {
            return null;
        }
        $thumb = preg_replace('/(\.[a-z0-9]+)$/i', '-thumb$1', $cover);

        return Storage::disk('public')->url($thumb !== $cover && Storage::disk('public')->exists($thumb) ? $thumb : $cover);
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
