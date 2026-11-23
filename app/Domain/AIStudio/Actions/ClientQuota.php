<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\AIStudio\Models\AIClientQuota;
use App\Domain\CRM\Models\Client;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Exceptions\DomainException;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Salon → client resale of AI looks: N free tries per client (or device) per month, then paid looks
 * (bought through the salon gateway) or extra tries granted by other modules (packages/memberships).
 * Tries are consumed only when a look succeeds.
 */
class ClientQuota
{
    /** @var list<Closure(Tenant, ?Client): int> extra monthly tries (e.g. from packages/memberships) */
    protected static array $extraTries = [];

    /** Other modules can grant included tries: ClientQuota::grantExtraTriesUsing(fn ($tenant, $client) => 5). */
    public static function grantExtraTriesUsing(Closure $resolver): void
    {
        static::$extraTries[] = $resolver;
    }

    public static function subject(?Client $client, ?string $device): string
    {
        return $client ? 'client:'.$client->id : 'device:'.substr((string) $device, 0, 64);
    }

    public function freeLimit(Tenant $tenant, ?Client $client = null): int
    {
        $base = (int) $tenant->setting('ai.free_tries_per_client', 3);
        foreach (static::$extraTries as $resolver) {
            $base += (int) $resolver($tenant, $client);
        }

        return $base;
    }

    /** @return array{free_limit:int, free_used:int, paid_balance:int, remaining:int} */
    public function status(Tenant $tenant, string $subject, ?Client $client = null): array
    {
        $row = $this->row($tenant->id, $subject, $client?->id);
        $limit = $this->freeLimit($tenant, $client);
        $free = max(0, $limit - $row->free_used);

        return ['free_limit' => $limit, 'free_used' => (int) $row->free_used, 'paid_balance' => (int) $row->paid_balance, 'remaining' => $free + (int) $row->paid_balance];
    }

    public function assertCanUse(Tenant $tenant, string $subject, ?Client $client = null): void
    {
        $key = 'ai-studio:'.$tenant->id.':'.$subject;
        $perHour = (int) $tenant->setting('ai.rate_limit_per_hour', config('ai.rate_limit_per_hour'));
        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            throw new DomainException(__('ai_studio.errors.rate_limited'));
        }
        if ($this->status($tenant, $subject, $client)['remaining'] <= 0) {
            throw new DomainException(__('ai_studio.errors.quota_exhausted'));
        }
        RateLimiter::hit($key, 3600);
    }

    public function consume(int $tenantId, string $subject, ?int $clientId = null): void
    {
        DB::transaction(function () use ($tenantId, $subject, $clientId) {
            $row = $this->row($tenantId, $subject, $clientId, lock: true);
            $tenant = Tenant::find($tenantId);
            $client = $clientId ? Client::withoutGlobalScopes()->find($clientId) : null;
            if ($row->free_used < $this->freeLimit($tenant, $client)) {
                $row->increment('free_used');
            } elseif ($row->paid_balance > 0) {
                $row->decrement('paid_balance');
                $row->increment('paid_used');
            } else {
                $row->increment('free_used'); // over-consumption from concurrent requests; never blocks a finished look
            }
        });
    }

    public function addPaid(int $tenantId, string $subject, int $looks, ?int $clientId = null): void
    {
        DB::transaction(fn () => $this->row($tenantId, $subject, $clientId, lock: true)->increment('paid_balance', $looks));
    }

    protected function row(int $tenantId, string $subject, ?int $clientId, bool $lock = false): AIClientQuota
    {
        $period = now()->format('Y-m');
        $query = AIClientQuota::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('subject', $subject)->where('period', $period);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($row) {
            return $row;
        }
        // Paid looks carry over from the previous month.
        $carry = (int) AIClientQuota::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('subject', $subject)->orderByDesc('period')->value('paid_balance');

        return AIClientQuota::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'subject' => $subject, 'period' => $period],
            ['client_id' => $clientId, 'device_fingerprint' => str_starts_with($subject, 'device:') ? substr($subject, 7) : null, 'paid_balance' => $carry],
        );
    }
}
