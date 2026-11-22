<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Reached only through a scoped Package. */
class PackageItem extends Model
{
    protected $guarded = ['id'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }
}
