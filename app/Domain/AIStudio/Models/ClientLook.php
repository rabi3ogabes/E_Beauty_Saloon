<?php

namespace App\Domain\AIStudio\Models;

use App\Domain\CRM\Models\Client;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A try-on result (before/after photos encrypted on the private disk). */
class ClientLook extends Model
{
    use BelongsToTenant;

    protected $table = 'client_looks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['saved' => 'boolean', 'shared_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $look) => $look->reference ??= 'LK'.Str::upper(Str::random(8)));
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(AIJob::class, 'ai_job_id');
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }
}
