<?php

namespace App\Domain\Assistant\Models;

use App\Domain\Booking\Models\Booking;
use App\Domain\CRM\Models\Client;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use BelongsToTenant;

    protected $table = 'assistant_conversations';

    protected $guarded = ['id'];

    protected $attributes = ['channel' => 'whatsapp', 'status' => 'bot', 'locale' => 'en', 'unread_count' => 0];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'assistant_conversation_id')->orderBy('id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lastBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'last_booking_id');
    }

    public function isHandedOff(): bool
    {
        return $this->status === 'handoff';
    }

    public function displayName(): string
    {
        return $this->client?->name ?: ($this->contact_name ?: $this->phone);
    }
}
