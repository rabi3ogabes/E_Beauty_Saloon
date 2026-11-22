<?php

namespace App\Domain\Loyalty\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\Loyalty\Models\LoyaltyEntry;
use App\Support\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Points ledger. Rules live in tenant settings "loyalty":
 *   points_per_currency_unit (default 1 point per 1 QAR), point_value (minor units per point, default 5 = 0.05 QAR),
 *   tiers: [{"key":"silver","min_points":0},{"key":"gold","min_points":2000},{"key":"black","min_points":6000}]
 */
class LoyaltyLedger
{
    public function earnForSpend(Client $client, int $spendMinor, ?Model $reference = null, ?string $key = null): ?LoyaltyEntry
    {
        $rate = (float) (tenant()?->setting('loyalty.points_per_currency_unit', 1) ?? 1);
        $points = (int) floor(($spendMinor / 100) * $rate);

        return $points > 0 ? $this->apply($client, $points, 'earn', $reference, $key) : null;
    }

    public function award(Client $client, int $points, string $type = 'bonus', ?Model $reference = null, ?string $key = null, ?string $note = null): LoyaltyEntry
    {
        return $this->apply($client, abs($points), $type, $reference, $key, $note);
    }

    public function redeem(Client $client, int $points, ?Model $reference = null, ?string $key = null): LoyaltyEntry
    {
        return $this->apply($client, -abs($points), 'redeem', $reference, $key);
    }

    /** Monetary value (minor units) of a number of points. */
    public function valueOf(int $points): int
    {
        return $points * (int) (tenant()?->setting('loyalty.point_value', 5) ?? 5);
    }

    public function pointsFor(int $amountMinor): int
    {
        return (int) ceil($amountMinor / max(1, (int) (tenant()?->setting('loyalty.point_value', 5) ?? 5)));
    }

    public function tierFor(int $lifetimePoints): string
    {
        $tiers = tenant()?->setting('loyalty.tiers') ?? [
            ['key' => 'silver', 'min_points' => 0], ['key' => 'gold', 'min_points' => 2000], ['key' => 'black', 'min_points' => 6000],
        ];
        $tier = 'silver';
        foreach (collect($tiers)->sortBy('min_points') as $t) {
            if ($lifetimePoints >= $t['min_points']) {
                $tier = $t['key'];
            }
        }

        return $tier;
    }

    protected function apply(Client $client, int $points, string $type, ?Model $reference, ?string $key, ?string $note = null): LoyaltyEntry
    {
        return DB::transaction(function () use ($client, $points, $type, $reference, $key, $note) {
            if ($key && ($existing = LoyaltyEntry::withoutGlobalScopes()->where('idempotency_key', $key)->first())) {
                return $existing;
            }
            $locked = Client::withoutGlobalScopes()->lockForUpdate()->findOrFail($client->id);
            $balance = $locked->loyalty_points + $points;
            if ($balance < 0) {
                throw new DomainException(__('salon.errors.insufficient_points', ['points' => $locked->loyalty_points]));
            }
            $earned = LoyaltyEntry::withoutGlobalScopes()->where('client_id', $locked->id)->where('points', '>', 0)->sum('points') + max(0, $points);
            $locked->update(['loyalty_points' => $balance, 'loyalty_tier' => $this->tierFor((int) $earned)]);
            $client->loyalty_points = $balance;

            return LoyaltyEntry::withoutGlobalScopes()->create([
                'tenant_id' => $locked->tenant_id, 'client_id' => $locked->id, 'type' => $type,
                'points' => $points, 'balance_after' => $balance,
                'reference_type' => $reference?->getMorphClass(), 'reference_id' => $reference?->getKey(),
                'idempotency_key' => $key, 'note' => $note,
            ]);
        });
    }
}
