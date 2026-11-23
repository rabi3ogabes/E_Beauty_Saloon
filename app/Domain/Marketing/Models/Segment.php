<?php

namespace App\Domain\Marketing\Models;

use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Services\SegmentQuery;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Segment extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected $attributes = ['cached_count' => 0];

    protected function casts(): array
    {
        return ['rules' => 'array', 'counted_at' => 'datetime'];
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /** @return Builder<Client> */
    public function clients(): Builder
    {
        return app(SegmentQuery::class)->apply(Client::query(), $this->rules ?? []);
    }

    public function refreshCount(): int
    {
        $count = $this->clients()->count();
        $this->forceFill(['cached_count' => $count, 'counted_at' => now()])->saveQuietly();

        return $count;
    }
}
