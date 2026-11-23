<?php

namespace App\Domain\Branches\Models;

use App\Domain\Catalog\Models\Service;
use App\Domain\Team\Models\Staff;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Translatable\HasTranslations;

class Branch extends Model
{
    use BelongsToTenant, HasTranslations, LogsActivity, SoftDeletes;

    protected $guarded = ['id'];

    public array $translatable = ['name', 'address'];

    public const WEEKDAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    protected static function booted(): void
    {
        // Users restricted to some branches never see the others.
        static::addGlobalScope('allowed_branches', function (Builder $q) {
            $allowed = app(TenantContext::class)->allowedBranchIds();
            if ($allowed !== null) {
                $q->whereIn($q->getModel()->qualifyColumn('id'), $allowed ?: [0]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'opening_hours' => 'array',
            'gallery' => 'array',
            'branding' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'tax_percent' => 'float',
            'tax_inclusive' => 'boolean',
            'booking_enabled' => 'boolean',
            'shop_pickup_enabled' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->logOnly(['name', 'status', 'opening_hours', 'booking_enabled']);
    }

    public static function defaultHours(): array
    {
        $hours = [];
        foreach (self::WEEKDAYS as $d) {
            $hours[$d] = ['open' => '10:00', 'close' => '22:00', 'closed' => false, 'breaks' => []];
        }

        return $hours;
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(BranchHoliday::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'branch_staff');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)->withPivot(['is_active', 'price', 'duration_minutes']);
    }

    /** Opening window for a date in branch local time, honoring holidays. Null = closed. */
    public function hoursFor(CarbonInterface $date): ?array
    {
        $this->loadMissing('holidays');
        $holiday = $this->holidays->first(fn ($h) => $h->date->isSameDay($date));
        if ($holiday) {
            return $holiday->opens_at ? ['open' => substr($holiday->opens_at, 0, 5), 'close' => substr($holiday->closes_at, 0, 5), 'breaks' => []] : null;
        }

        $day = ($this->opening_hours ?? self::defaultHours())[self::WEEKDAYS[$date->dayOfWeek]] ?? null;

        return (! $day || ($day['closed'] ?? false)) ? null : $day;
    }

    public function isOpenNow(): bool
    {
        $now = now($this->timezone);
        $h = $this->hoursFor($now);

        return $h && $now->format('H:i') >= $h['open'] && $now->format('H:i') < $h['close'];
    }

    public function scopeActive($q)
    {
        return $q->where('status', 'active');
    }
}
