<?php

namespace App\Domain\POS\Models;

use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A cash register / till at a branch. One open shift at a time. */
class Register extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    protected $table = 'pos_registers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class, 'pos_register_id');
    }

    public function openShift(): HasOne
    {
        return $this->hasOne(Shift::class, 'pos_register_id')->where('status', 'open')->latestOfMany('opened_at');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'pos_register_id');
    }
}
