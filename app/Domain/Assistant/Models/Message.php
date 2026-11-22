<?php

namespace App\Domain\Assistant\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToTenant;

    protected $table = 'assistant_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tool_calls' => 'array', 'confidence' => 'float'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'assistant_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
