<?php

namespace App\Domain\Insights\Models;

use App\Domain\Booking\Models\Booking;
use App\Domain\CRM\Models\Client;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consultation form sent to a client by link before a visit. answers (encrypted):
 *   {patch_test: yes|no|na, patch_test_date, allergies, medications, pregnant: bool, skin_conditions, notes, consent: bool}
 */
class ConsultationForm extends Model
{
    use BelongsToTenant;

    public const QUESTIONS = ['patch_test', 'patch_test_date', 'allergies', 'medications', 'pregnant', 'skin_conditions', 'notes'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'sent', 'locale' => 'en'];

    protected function casts(): array
    {
        return [
            'answers' => 'encrypted:array',
            'sent_at' => 'datetime', 'signed_at' => 'datetime', 'expires_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }

    public function isExpired(): bool
    {
        return ! $this->isSigned() && $this->expires_at && $this->expires_at->isPast();
    }

    /** Host-relative public link (works on subdomains and custom domains). */
    public function publicPath(): string
    {
        return '/consultation/'.$this->token;
    }
}
