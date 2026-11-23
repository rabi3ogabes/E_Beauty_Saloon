<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Reached only through a scoped ClientPackage. */
class ClientPackageUsage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }

    public function clientPackage(): BelongsTo
    {
        return $this->belongsTo(ClientPackage::class);
    }
}
