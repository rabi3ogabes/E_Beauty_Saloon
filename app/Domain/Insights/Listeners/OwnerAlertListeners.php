<?php

namespace App\Domain\Insights\Listeners;

use App\Domain\Booking\Events\BookingCheckedIn;
use App\Domain\Insights\Actions\RaiseOwnerAlert;
use App\Domain\Insights\Support\InsightSettings;
use App\Domain\Inventory\Models\BranchStock;
use App\Domain\Marketing\Models\Review;
use App\Domain\Payments\Events\PaymentFailed;
use App\Domain\POS\Enums\SaleStatus;
use App\Domain\POS\Models\Sale;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * Wires business events to owner alerts. Event listeners for domain events; Eloquent model events for modules that
 * do not dispatch one (POS sale completion, stock level, reviews). Each handler re-enters the row's tenant.
 */
class OwnerAlertListeners
{
    public function __construct(protected RaiseOwnerAlert $alerts, protected TenantContext $context) {}

    protected function inTenant(int $tenantId, \Closure $fn): void
    {
        if (tenant()?->id === $tenantId) {
            $fn();

            return;
        }
        if ($tenant = Tenant::find($tenantId)) {
            $this->context->run($tenant, $fn);
        }
    }

    public function saleSaved(Sale $sale): void
    {
        if ($sale->status !== SaleStatus::Completed || ! ($sale->wasRecentlyCreated || $sale->wasChanged('status'))) {
            return;
        }
        $this->inTenant((int) $sale->tenant_id, function () use ($sale) {
            $net = (int) $sale->total - (int) $sale->tips;
            $threshold = (int) InsightSettings::get('alerts.big_sale_threshold', 100000);
            if ($threshold > 0 && $net >= $threshold) {
                $this->alerts->handle('big_sale', 'big_sale:'.$sale->id, [
                    'amount' => money($net), 'branch' => (string) $sale->branch?->name, 'client' => $sale->client?->name ?? __('insights.alerts.walk_in'),
                ]);
            }
        });
    }

    public function checkedIn(BookingCheckedIn $event): void
    {
        $booking = $event->booking;
        if (! $booking->client?->is_vip) {
            return;
        }
        $this->inTenant((int) $booking->tenant_id, fn () => $this->alerts->handle('vip_checkin', 'vip:'.$booking->id, [
            'client' => $booking->client->name, 'branch' => (string) $booking->branch?->name,
            'time' => $booking->starts_at->copy()->setTimezone($booking->branch?->timezone ?? 'UTC')->format('H:i'),
        ]));
    }

    public function reviewCreated(Review $review): void
    {
        if ((int) $review->rating > 2) {
            return;
        }
        // Marketing already puts low ratings in the bell (LowRatingReceived) — add the WhatsApp ping only.
        $this->inTenant((int) $review->tenant_id, fn () => $this->alerts->handle('bad_review', 'review:'.$review->id, [
            'rating' => (int) $review->rating, 'client' => (string) $review->client?->name, 'comment' => mb_substr((string) $review->comment, 0, 140),
        ], bell: false));
    }

    public function stockUpdated(BranchStock $stock): void
    {
        if (! $stock->wasChanged('qty')) {
            return;
        }
        $before = (int) $stock->getOriginal('qty');
        if ($stock->qty > $stock->low_stock_threshold || $before <= $stock->low_stock_threshold) {
            return; // only when crossing the threshold
        }
        $this->inTenant((int) $stock->tenant_id, function () use ($stock) {
            $stock->loadMissing('variant.product', 'branch');
            $this->alerts->handle('low_stock', 'low_stock:'.$stock->branch_id.':'.$stock->product_variant_id.':'.now()->toDateString(), [
                'item' => $stock->variant?->displayName() ?? '#'.$stock->product_variant_id, 'qty' => (int) $stock->qty, 'branch' => (string) $stock->branch?->name,
            ]);
        });
    }

    public function paymentFailed(PaymentFailed $event): void
    {
        $payment = $event->payment;
        if (! $payment->tenant_id) {
            return;
        }
        $this->inTenant((int) $payment->tenant_id, fn () => $this->alerts->handle('failed_payment', 'payment:'.$payment->id, [
            'amount' => money((int) $payment->amount), 'reason' => mb_substr((string) ($payment->failure_reason ?: '—'), 0, 120),
        ]));
    }
}
