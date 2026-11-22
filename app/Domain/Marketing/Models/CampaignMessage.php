<?php

namespace App\Domain\Marketing\Models;

use App\Domain\Booking\Models\Booking;
use App\Domain\CRM\Models\Client;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CampaignMessage extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['held', 'queued', 'sent', 'delivered', 'read', 'clicked', 'failed', 'skipped_no_consent', 'skipped_quiet_hours', 'skipped_credits'];

    /** Statuses that count as "reached the phone". */
    public const SENT_STATUSES = ['sent', 'delivered', 'read', 'clicked'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime',
            'clicked_at' => 'datetime', 'booked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->token ??= Str::random(24));
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(CampaignVariant::class, 'campaign_variant_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** Advance the delivery status without ever going backwards (sent < delivered < read < clicked). */
    public function advanceTo(string $status, ?\DateTimeInterface $at = null): void
    {
        $order = ['queued' => 0, 'skipped_quiet_hours' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'clicked' => 4];
        $at ??= now();
        $column = ['delivered' => 'delivered_at', 'read' => 'read_at', 'clicked' => 'clicked_at', 'sent' => 'sent_at'][$status] ?? null;
        $updates = [];
        if ($column && ! $this->{$column}) {
            $updates[$column] = $at;
        }
        if ($status === 'failed' && ! in_array($this->status, ['read', 'clicked'], true)) {
            $updates['status'] = 'failed';
        } elseif (($order[$status] ?? -1) > ($order[$this->status] ?? -1)) {
            $updates['status'] = $status;
        }
        if ($updates) {
            $this->update($updates);
        }
    }
}
