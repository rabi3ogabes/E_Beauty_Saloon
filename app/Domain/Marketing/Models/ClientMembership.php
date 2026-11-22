<?php

namespace App\Domain\Marketing\Models;

use App\Domain\CRM\Models\Client;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientMembership extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'renews_at' => 'datetime', 'perks_used' => 'array', 'auto_renew' => 'boolean', 'renewal_reminded_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class)->withTrashed();
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ($this->renews_at === null || $this->renews_at->isFuture());
    }
}
