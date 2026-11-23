<?php

namespace App\Domain\Shop\Models;

use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class DeliveryZone extends Model
{
    use BelongsToBranch, BelongsToTenant, HasTranslations;

    protected $guarded = ['id'];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'areas' => 'array',
            'polygon' => 'array',
            'same_day_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function coversArea(?string $area): bool
    {
        $area = mb_strtolower(trim((string) $area));

        return $area !== '' && collect($this->areas ?? [])->contains(fn ($a) => mb_strtolower(trim((string) $a)) === $area);
    }

    /** Ray-casting point-in-polygon on [[lat,lng],...]. */
    public function containsPoint(?float $lat, ?float $lng): bool
    {
        $poly = array_values($this->polygon ?? []);
        $n = count($poly);
        if ($lat === null || $lng === null || $n < 3) {
            return false;
        }
        $inside = false;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$yi, $xi] = [(float) $poly[$i][0], (float) $poly[$i][1]];
            [$yj, $xj] = [(float) $poly[$j][0], (float) $poly[$j][1]];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
