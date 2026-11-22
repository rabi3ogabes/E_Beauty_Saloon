<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\AIStudio\Models\AIWallet;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/** Monthly included credits from the plan (included_ai_credits). Idempotent per tenant + month. */
class GrantMonthlyCredits
{
    public function __construct(protected CreditLedger $ledger) {}

    public function handle(?Tenant $only = null): int
    {
        $period = now()->format('Y-m');
        $granted = 0;
        $tenants = $only ? collect([$only]) : Tenant::query()->with('currentSubscription.plan')->get();

        foreach ($tenants as $tenant) {
            if (! $tenant->hasModule('ai_studio')) {
                continue;
            }
            $credits = (int) ($tenant->currentSubscription?->plan?->included_ai_credits ?? 0);
            app(TenantContext::class)->run($tenant, function () use ($tenant, $credits, $period, &$granted) {
                $wallet = AIWallet::for($tenant);
                $wallet->forceFill(['monthly_included' => $credits])->save();
                if ($credits <= 0 || $wallet->included_period === $period) {
                    return;
                }
                $this->ledger->credit($tenant, $credits, 'included', 'included:'.$tenant->id.':'.$period, __('ai_studio.wallet.included', ['period' => $period]));
                $wallet->refresh()->forceFill(['included_period' => $period])->save();
                $granted++;
            });
        }

        return $granted;
    }
}
