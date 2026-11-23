<?php

namespace App\Domain\Booking\Models;

use App\Domain\Branches\Models\Resource;
use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceVariant;
use App\Domain\Team\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'addon_ids' => 'array'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ServiceVariant::class, 'service_variant_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withoutGlobalScopes();
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
