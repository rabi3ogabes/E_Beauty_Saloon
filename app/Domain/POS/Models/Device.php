<?php

namespace App\Domain\POS\Models;

use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Optional hardware/browser registry (tablet, receipt printer, card terminal, scanner). */
class Device extends Model
{
    use BelongsToBranch, BelongsToTenant;

    protected $table = 'pos_devices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class, 'pos_register_id');
    }
}
