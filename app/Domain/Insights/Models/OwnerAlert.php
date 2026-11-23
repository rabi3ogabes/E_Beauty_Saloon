<?php

namespace App\Domain\Insights\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class OwnerAlert extends Model
{
    use BelongsToTenant;

    public const TYPES = ['big_sale', 'vip_checkin', 'bad_review', 'low_stock', 'failed_payment'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'sent_whatsapp' => 'boolean'];
    }
}
