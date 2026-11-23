<?php

namespace App\Domain\Api\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Stored response of a POST that carried an Idempotency-Key header (replayed on retry for 24h). */
class IdempotencyKey extends Model
{
    use BelongsToTenant;

    protected $table = 'api_idempotency_keys';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
