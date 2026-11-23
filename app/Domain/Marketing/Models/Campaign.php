<?php

namespace App\Domain\Marketing\Models;

use App\Domain\Booking\Models\Booking;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * content: {"en": {"subject": "...", "body": "Hi {first_name} ... {link}"}, "ar": {...}}
 * Placeholders: {first_name} {name} {salon} {link} (tracked short link) {unsubscribe} {points} {code}
 */
class Campaign extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft', 'scheduled', 'sending', 'testing', 'sent', 'cancelled', 'failed'];

    /** Bookings created within this window after a message is attributed to the campaign. */
    public const ATTRIBUTION_DAYS = 7;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'ab_enabled' => false, 'ab_test_percent' => 20, 'ab_metric' => 'click', 'ab_wait_hours' => 4];

    protected function casts(): array
    {
        return [
            'channels' => 'array', 'content' => 'array', 'ab_enabled' => 'boolean',
            'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'ab_decided_at' => 'datetime',
        ];
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    public function banner(): BelongsTo
    {
        return $this->belongsTo(Banner::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsappTemplate::class, 'whatsapp_template_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(CampaignVariant::class)->orderBy('key');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CampaignMessage::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'campaign_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }

    /** Banner PNG (exported in the studio) or an explicit media URL. */
    public function mediaUrl(): ?string
    {
        return $this->banner?->imageUrl() ?? $this->media_url;
    }

    public function ctaUrl(): string
    {
        return $this->cta_url ?: '/book';
    }
}
