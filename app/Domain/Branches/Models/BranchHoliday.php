<?php

namespace App\Domain\Branches\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchHoliday extends Model
{
    use BelongsToTenant;

    protected $table = 'branch_holidays';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
