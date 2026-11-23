<?php

namespace App\Domain\Team\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CommissionRule extends Model
{
    use BelongsToTenant;

    protected $table = 'commission_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tiers' => 'array',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
