<?php

namespace App\Domain\Insights\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ReportDelivery extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'channels' => 'array'];
    }
}
