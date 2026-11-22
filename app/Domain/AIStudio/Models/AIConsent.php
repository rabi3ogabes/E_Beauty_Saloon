<?php

namespace App\Domain\AIStudio\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AIConsent extends Model
{
    use BelongsToTenant;

    protected $table = 'ai_consents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['age_confirmed' => 'boolean', 'is_minor' => 'boolean', 'accepted_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }
}
