<?php

namespace App\Domain\Billing\Jobs;

use App\Domain\Billing\Actions\RenewSubscription;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Notifications\SubscriptionRenewalReminder;
use App\Domain\Billing\Notifications\SubscriptionSuspended;
use App\Domain\Tenancy\Enums\TenantStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Daily billing lifecycle:
 *  1. Reminders 7/3/1 days before ends_at (email + WhatsApp when configured)
 *  2. Renewal invoice generated 7 days before ends_at (auto_renew)
 *  3. Expired & unpaid -> grace (read-only) for N days
 *  4. Grace over -> suspended (dashboard locked, website "temporarily unavailable")
 */
class ProcessSubscriptionLifecycle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(RenewSubscription $renew): void
    {
        $live = [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value];

        Subscription::query()->with(['tenant.owner', 'plan', 'coupon'])->whereIn('status', $live)->whereNotNull('ends_at')
            ->where('ends_at', '<=', now()->addDays(max(config('salon.renewal_reminder_days')) + 1))
            ->chunkById(100, function ($subs) use ($renew) {
                foreach ($subs as $sub) {
                    $days = (int) ceil(now()->floatDiffInDays($sub->ends_at, false));

                    if ($sub->auto_renew && $days <= 7 && $days >= 0) {
                        $invoice = $renew->handle($sub);
                        if (in_array($days, config('salon.renewal_reminder_days'), true)) {
                            $sub->tenant->owner?->notify(new SubscriptionRenewalReminder($sub, $invoice, $days));
                        }
                    }

                    if ($sub->ends_at->isPast()) {
                        $sub->update(['status' => SubscriptionStatus::Grace]);
                        $sub->tenant->update(['status' => TenantStatus::Grace, 'grace_ends_at' => now()->addDays(config('salon.grace_days'))]);
                    }
                }
            });

        Subscription::query()->with('tenant.owner')->where('status', SubscriptionStatus::Grace->value)
            ->whereHas('tenant', fn ($q) => $q->where('grace_ends_at', '<', now()))
            ->chunkById(100, function ($subs) {
                foreach ($subs as $sub) {
                    $sub->update(['status' => SubscriptionStatus::Suspended]);
                    $sub->tenant->update(['status' => TenantStatus::Suspended]);
                    $sub->tenant->owner?->notify(new SubscriptionSuspended($sub));
                    activity()->performedOn($sub->tenant)->log('tenant.suspended');
                }
            });
    }
}
