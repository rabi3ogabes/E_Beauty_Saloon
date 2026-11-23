<?php

namespace App\Domain\Booking\Models;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\CRM\Models\Client;
use App\Domain\Payments\Models\Payment;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Shared core model. Business logic (availability, create/reschedule/cancel, payments) lives in
 * App\Domain\Booking\Actions. Times are stored in UTC; display in the branch timezone.
 */
class Booking extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminder_24h_sent_at' => 'datetime',
            'reminder_2h_sent_at' => 'datetime',
            'review_requested_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $b) {
            if (! $b->reference) {
                do {
                    $ref = strtoupper(Str::random(6));
                } while (static::withoutGlobalScopes()->where('tenant_id', $b->tenant_id)->where('reference', $ref)->exists());
                $b->reference = preg_replace('/[^A-Z0-9]/', 'X', $ref);
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class)->orderBy('sort');
    }

    public function history(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class)->latest('id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function balanceDue(): int
    {
        return max(0, $this->total - $this->amount_paid);
    }

    public function localStart(): CarbonInterface
    {
        return $this->starts_at->copy()->setTimezone($this->branch?->timezone ?? config('salon.defaults.timezone'));
    }

    public function scopeBlocking($q)
    {
        return $q->whereIn('status', array_map(fn ($s) => $s->value, BookingStatus::blocking()));
    }

    public function scopeUpcoming($q)
    {
        return $q->where('starts_at', '>=', now())->orderBy('starts_at');
    }
}
