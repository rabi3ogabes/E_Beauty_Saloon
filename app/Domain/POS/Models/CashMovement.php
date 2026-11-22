<?php

namespace App\Domain\POS\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    use BelongsToTenant;

    protected $table = 'pos_cash_movements';

    protected $guarded = ['id'];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'pos_shift_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
