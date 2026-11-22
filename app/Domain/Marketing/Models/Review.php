<?php

namespace App\Domain\Marketing\Models;

use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Branch;
use App\Domain\CRM\Models\Client;
use App\Domain\Team\Models\Staff;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['published', 'private', 'pending'];

    /** Ratings at or above this are published automatically; below are routed privately to the owner. */
    public const PUBLISH_THRESHOLD = 4;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending', 'source' => 'website'];

    protected function casts(): array
    {
        return ['replied_at' => 'datetime', 'rating' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withoutGlobalScopes();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withoutGlobalScopes();
    }

    public function scopePublished($q)
    {
        return $q->where('status', 'published');
    }
}
