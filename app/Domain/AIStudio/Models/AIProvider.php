<?php

namespace App\Domain\AIStudio\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An AI vendor account. Platform rows have tenant_id = null; tenant rows are "bring your own key".
 * Not tenant-scoped on purpose: the router merges platform + current tenant rows explicitly.
 */
class AIProvider extends Model
{
    protected $table = 'ai_providers';

    protected $guarded = ['id'];

    protected $hidden = ['api_key'];

    public const DRIVERS = ['gemini', 'openai', 'higgsfield', 'anthropic', 'http'];

    public const DRIVER_CAPABILITIES = [
        'gemini' => ['image', 'text'],
        'openai' => ['image', 'text'],
        'higgsfield' => ['image', 'video'],
        'anthropic' => ['text'],
        'http' => ['image', 'text'],
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'models' => 'array',
            'capabilities' => 'array',
            'unit_costs' => 'array',
            'options' => 'array',
            'enabled' => 'boolean',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?: (self::DRIVER_CAPABILITIES[$this->driver] ?? []), true);
    }

    public function model(string $capability): ?string
    {
        return data_get($this->models, $capability) ?? config("ai.providers.{$this->driver}.models.{$capability}");
    }

    public function unitCost(string $capability): int
    {
        return (int) data_get($this->unit_costs, $capability, 0);
    }

    public function baseUrl(): string
    {
        return rtrim($this->base_url ?: (string) config("ai.providers.{$this->driver}.base_url"), '/');
    }

    public function key(): ?string
    {
        return $this->api_key ?: config("ai.providers.{$this->driver}.api_key");
    }

    /** Platform rows + the given tenant's own (BYOK) rows. */
    public function scopeAvailableFor(Builder $q, ?int $tenantId): Builder
    {
        return $q->where('enabled', true)->where(fn ($w) => $w->whereNull('tenant_id')->when($tenantId, fn ($w2) => $w2->orWhere('tenant_id', $tenantId)));
    }

    public function scopePlatform(Builder $q): Builder
    {
        return $q->whereNull('tenant_id');
    }

    /** Record a successful call: latency EMA, reset failures, healthy. */
    public function recordSuccess(int $latencyMs): void
    {
        $ema = $this->latency_ms_ema ? (int) round(0.3 * $latencyMs + 0.7 * $this->latency_ms_ema) : $latencyMs;
        $this->forceFill([
            'latency_ms_ema' => $ema,
            'consecutive_failures' => 0,
            'health_status' => 'healthy',
            'total_calls' => $this->total_calls + 1,
            'last_success_at' => now(),
        ])->saveQuietly();
    }

    /** Record a failed call: degraded after 1 failure, down after 3 in a row. */
    public function recordFailure(string $error, ?int $latencyMs = null): void
    {
        $failures = $this->consecutive_failures + 1;
        $this->forceFill([
            'consecutive_failures' => $failures,
            'health_status' => $failures >= 3 ? 'down' : 'degraded',
            'total_calls' => $this->total_calls + 1,
            'total_failures' => $this->total_failures + 1,
            'last_failure_at' => now(),
            'last_error' => mb_substr($error, 0, 250),
            'latency_ms_ema' => $latencyMs && $this->latency_ms_ema ? (int) round(0.3 * $latencyMs + 0.7 * $this->latency_ms_ema) : $this->latency_ms_ema,
        ])->saveQuietly();
    }

    /** A "down" provider gets a retry window every 5 minutes (circuit breaker half-open). */
    public function isRoutable(): bool
    {
        // A provider without credentials can never succeed: skip it instead of failing a client's look on it.
        if (blank($this->key())) {
            return false;
        }

        return $this->health_status !== 'down' || ! $this->last_failure_at || $this->last_failure_at->lt(now()->subMinutes(5));
    }
}
