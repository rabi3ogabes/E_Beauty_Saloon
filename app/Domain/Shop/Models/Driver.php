<?php

namespace App\Domain\Shop\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Team\Models\Staff;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

class Driver extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withoutGlobalScopes();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Permanent signed link to the driver web view (no login needed). Revoke by deactivating the driver. */
    public function signedUrl(): string
    {
        return URL::signedRoute('shop.driver.show', ['driver' => $this->id]);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
