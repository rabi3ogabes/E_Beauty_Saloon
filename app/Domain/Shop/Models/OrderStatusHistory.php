<?php

namespace App\Domain\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Child of Order — only reached through a tenant-scoped order. */
class OrderStatusHistory extends Model
{
    protected $table = 'order_status_history';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $with = ['user'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
