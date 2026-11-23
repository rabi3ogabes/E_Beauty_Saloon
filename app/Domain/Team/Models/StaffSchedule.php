<?php

namespace App\Domain\Team\Models;

use App\Domain\Branches\Models\Branch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffSchedule extends Model
{
    use BelongsToTenant;

    protected $table = 'staff_schedules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'breaks' => 'array',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
