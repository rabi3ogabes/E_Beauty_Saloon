<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Subscription extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'modules' => 'array',
            'price_snapshot' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'auto_renew' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'plan_id', 'branches_count', 'modules', 'ends_at'])->logOnlyDirty();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function daysRemaining(): int
    {
        return $this->ends_at ? max(0, (int) now()->diffInDays($this->ends_at, false)) : 0;
    }

    /** Fraction (0..1) of the current cycle still unused — used for proration. */
    public function remainingFraction(): float
    {
        if (! $this->starts_at || ! $this->ends_at || $this->ends_at->isPast()) {
            return 0.0;
        }
        $total = max(1, $this->starts_at->diffInSeconds($this->ends_at));

        return min(1, max(0, now()->diffInSeconds($this->ends_at) / $total));
    }
}
