<?php

namespace App\Domain\Insights\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsightRun extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['run_date' => 'date', 'used_ai' => 'boolean'];
    }
}
