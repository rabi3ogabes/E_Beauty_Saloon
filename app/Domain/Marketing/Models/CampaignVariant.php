<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A/B variant. Reached only through a scoped Campaign. */
class CampaignVariant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'array', 'is_winner' => 'boolean'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CampaignMessage::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsappTemplate::class, 'whatsapp_template_id');
    }
}
