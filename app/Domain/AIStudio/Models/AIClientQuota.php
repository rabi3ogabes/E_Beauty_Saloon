<?php

namespace App\Domain\AIStudio\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AIClientQuota extends Model
{
    use BelongsToTenant;

    protected $table = 'ai_client_quotas';

    protected $guarded = ['id'];
}
