<?php

namespace App\Domain\POS\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use BelongsToBranch, BelongsToTenant;

    protected $table = 'pos_shifts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'summary' => 'array'];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class, 'pos_register_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'pos_shift_id')->latest('id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'pos_shift_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(SaleRefund::class, 'pos_shift_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
