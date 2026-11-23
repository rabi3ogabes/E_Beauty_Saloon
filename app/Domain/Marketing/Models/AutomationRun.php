<?php

namespace App\Domain\Marketing\Models;

use App\Domain\CRM\Models\Client;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRun extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'running', 'current_step' => 0];

    protected function casts(): array
    {
        return ['context' => 'array', 'log' => 'array', 'resume_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function addLog(string $line): void
    {
        $log = $this->log ?? [];
        $log[] = now()->toIso8601String().' '.$line;
        $this->log = $log;
    }
}
