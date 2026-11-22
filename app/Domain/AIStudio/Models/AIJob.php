<?php

namespace App\Domain\AIStudio\Models;

use App\Domain\CRM\Models\Client;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AIJob extends Model
{
    use BelongsToTenant;

    protected $table = 'ai_jobs';

    protected $guarded = ['id'];

    public const STATUSES = ['queued', 'running', 'succeeded', 'failed', 'refunded'];

    protected function casts(): array
    {
        return [
            'input_refs' => 'array',
            'output_refs' => 'array',
            'attempt_log' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AIProvider::class, 'ai_provider_id');
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }

    public function look(): HasOne
    {
        return $this->hasOne(ClientLook::class, 'ai_job_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'refunded'], true);
    }
}
