<?php

namespace App\Domain\AIStudio\Models;

use App\Domain\AIStudio\Support\AtelierCatalog;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A look designed in the Live Atelier and saved by a client (or an anonymous device). Settings only. */
class AtelierDesign extends Model
{
    use BelongsToTenant;

    public const MAX_PER_OWNER = 12;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['params' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $d) => $d->reference ??= 'AT'.Str::upper(Str::random(8)));
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function scopeOwnedBy(Builder $q, ?Client $client, ?string $device): Builder
    {
        return $q->where(fn ($w) => $client
            ? $w->where('client_id', $client->id)->orWhere(fn ($d) => $d->whereNull('client_id')->where('device_fingerprint', $device))
            : $w->whereNull('client_id')->where('device_fingerprint', $device));
    }

    public function localizedSummary(?string $locale = null): string
    {
        return AtelierCatalog::has($this->category) ? AtelierCatalog::summary($this->category, $this->params, $locale) : $this->summary;
    }
}
