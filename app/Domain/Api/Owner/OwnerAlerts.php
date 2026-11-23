<?php

namespace App\Domain\Api\Owner;

use App\Domain\AIStudio\Models\AIWallet;
use App\Domain\Inventory\Models\BranchStock;
use App\Domain\Marketing\Models\MessageCreditWallet;
use App\Domain\Marketing\Models\Review;
use App\Domain\Payments\Models\Payment;
use App\Domain\Team\Models\TimeOff;
use App\Models\User;
use App\Support\Money\Money;

/**
 * "What needs my attention" for the owner app: low stock, pending time off, failed online payments (24h), low
 * AI Studio credits, low message credits and bad reviews (≤ 2★, 7 days). Each alert is computed on the fly
 * (no table) and only for modules the salon has and permissions the member holds.
 *
 * @return list<array{id: string, type: string, severity: string, title: string, body: ?string, count: int, created_at: ?string, action: ?array}>
 */
class OwnerAlerts
{
    public function handle(User $user, ?int $branchId = null): array
    {
        $tenant = tenant();
        $alerts = [];

        if ($user->can('inventory.view') || $user->can('reports.view')) {
            $low = BranchStock::query()->low()->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->with('variant.product')->orderBy('qty')->limit(50)->get();
            if ($low->isNotEmpty()) {
                $alerts[] = $this->alert('low_stock', $low->contains(fn ($s) => $s->qty <= 0) ? 'critical' : 'warning', $low->count(),
                    __('api.owner.alerts.low_stock', ['count' => $low->count()]),
                    __('api.owner.alerts.low_stock_body', ['items' => $low->take(5)->map(fn ($s) => trim(($s->variant?->product?->name ?? '').' '.($s->variant?->getTranslation('name', app()->getLocale(), false) ?? '')).' ('.$s->qty.')')->implode(', ')]),
                    $low->max('updated_at'), ['screen' => 'inventory']);
            }
        }

        if ($user->can('staff.manage')) {
            $pending = TimeOff::query()->where('status', 'pending')->with('staff')->latest()->get();
            if ($pending->isNotEmpty()) {
                $alerts[] = $this->alert('time_off', 'info', $pending->count(), __('api.owner.alerts.time_off', ['count' => $pending->count()]),
                    __('api.owner.alerts.time_off_body', ['names' => $pending->map(fn ($t) => $t->staff?->name)->filter()->unique()->take(5)->implode(', ')]),
                    $pending->max('created_at'), ['screen' => 'time_off']);
            }
        }

        if ($user->can('reports.revenue') || $user->can('payments.manage')) {
            $failed = Payment::query()->where('status', 'failed')->where('updated_at', '>=', now()->subDay())
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->get(['id', 'updated_at']);
            if ($failed->isNotEmpty()) {
                $alerts[] = $this->alert('failed_payments', 'warning', $failed->count(), __('api.owner.alerts.failed_payments', ['count' => $failed->count()]),
                    __('api.owner.alerts.failed_payments_body'), $failed->max('updated_at'), ['screen' => 'payments']);
            }
        }

        if ($tenant->hasModule('ai_studio')) {
            $wallet = AIWallet::query()->where('tenant_id', $tenant->id)->first();
            if ($wallet && $wallet->isLow()) {
                $alerts[] = $this->alert('ai_credits', $wallet->balance <= 0 ? 'critical' : 'warning', (int) $wallet->balance,
                    __('api.owner.alerts.ai_credits', ['balance' => (int) $wallet->balance]), __('api.owner.alerts.ai_credits_body'), $wallet->updated_at, ['screen' => 'ai_credits']);
            }
        }

        if ($tenant->hasModule('marketing')) {
            $credits = MessageCreditWallet::query()->where('tenant_id', $tenant->id)->first();
            if ($credits && $credits->balance <= $credits->low_balance_threshold) {
                $alerts[] = $this->alert('message_credits', $credits->balance <= 0 ? 'critical' : 'warning', (int) $credits->balance,
                    __('api.owner.alerts.message_credits', ['balance' => (string) Money::of((int) $credits->balance, $credits->currency ?: ($tenant->currency ?? 'QAR'))]),
                    __('api.owner.alerts.message_credits_body'), $credits->updated_at, ['screen' => 'message_credits']);
            }

            $reviews = Review::query()->where('rating', '<=', 2)->where('created_at', '>=', now()->subDays(7))
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->with('client')->latest()->limit(10)->get();
            foreach ($reviews as $review) {
                $alerts[] = $this->alert('bad_review', $review->rating <= 1 ? 'critical' : 'warning', 1,
                    __('api.owner.alerts.bad_review', ['rating' => $review->rating, 'client' => $review->client?->firstName() ?? __('account.guest')]),
                    $review->comment ? __('api.owner.alerts.bad_review_body', ['comment' => mb_strimwidth($review->comment, 0, 160, '…')]) : null,
                    $review->created_at, ['screen' => 'review', 'id' => $review->id], 'bad_review:'.$review->id);
            }
        }

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, fn ($a, $b) => [$rank[$a['severity']], $b['created_at'] ?? ''] <=> [$rank[$b['severity']], $a['created_at'] ?? '']);

        return $alerts;
    }

    protected function alert(string $type, string $severity, int $count, string $title, ?string $body, $at, ?array $action = null, ?string $id = null): array
    {
        return [
            'id' => $id ?? $type,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'count' => $count,
            'created_at' => $at?->copy()->utc()->toIso8601ZuluString(),
            'action' => $action,
        ];
    }
}
