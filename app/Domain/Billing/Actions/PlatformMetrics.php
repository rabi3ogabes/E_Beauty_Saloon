<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PlatformPayment;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/** Super admin KPIs (cached 5 minutes). MRR normalizes each live subscription's cycle total to a month. */
class PlatformMetrics
{
    public function summary(): array
    {
        return Cache::remember('platform-metrics', 300, function () {
            $active = Subscription::with('plan')->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::Grace->value])->get();
            $mrr = (int) $active->sum(fn ($s) => ($s->price_snapshot['total'] ?? 0) / max(1, $s->cycle_months));

            $startCount = Tenant::where('created_at', '<', now()->subDays(30))->whereIn('status', [TenantStatus::Active->value, TenantStatus::Grace->value, TenantStatus::Suspended->value, TenantStatus::Cancelled->value])->count();
            $churned = Tenant::whereIn('status', [TenantStatus::Suspended->value, TenantStatus::Cancelled->value])->where('updated_at', '>=', now()->subDays(30))->count();

            $byModule = [];
            foreach ($active as $s) {
                foreach (($s->price_snapshot['lines'] ?? []) as $line) {
                    $key = $line['type'] === 'module' ? ($line['meta']['module'] ?? 'other') : ($line['type'] === 'plan' ? 'plan' : 'branches');
                    $byModule[$key] = ($byModule[$key] ?? 0) + (int) round($line['amount'] / max(1, $s->cycle_months));
                }
            }

            $monthly = collect(range(11, 0))->mapWithKeys(function ($i) {
                $month = now()->startOfMonth()->subMonths($i);

                return [$month->format('M y') => (int) PlatformPayment::where('status', 'succeeded')
                    ->whereBetween('paid_at', [$month, $month->copy()->endOfMonth()])->sum('amount')];
            });

            return [
                'mrr' => $mrr,
                'arr' => $mrr * 12,
                'active_tenants' => Tenant::where('status', TenantStatus::Active)->count(),
                'trials' => Tenant::where('status', TenantStatus::Trial)->count(),
                'churn_percent' => $startCount ? round($churned / $startCount * 100, 1) : 0.0,
                'renewals_week' => Subscription::whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value])->whereBetween('ends_at', [now(), now()->addDays(7)])->count(),
                'overdue_invoices' => Invoice::where('status', 'open')->where('due_at', '<', now())->count(),
                'failed_payments' => PlatformPayment::where('status', 'failed')->where('created_at', '>=', now()->subDays(30))->count(),
                'revenue_by_module' => $byModule,
                'collected_by_month' => $monthly->all(),
            ];
        });
    }
}
