<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignMessage;
use App\Support\Tenancy\BranchScope;
use Illuminate\Support\Carbon;

/**
 * Funnel + revenue for a campaign. Bookings are attributed when they carry campaign_id and were created
 * between the launch and 7 days after the last send. Cancelled / no-show bookings bring no revenue.
 */
class CampaignReport
{
    public function for(Campaign $campaign): array
    {
        $messages = $campaign->messages();
        $byStatus = (clone $messages)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->map(fn ($c) => (int) $c);
        $sent = (clone $messages)->whereNotNull('sent_at')->count();
        $delivered = (clone $messages)->whereNotNull('sent_at')->where(fn ($q) => $q->whereNotNull('delivered_at')->orWhereNotNull('read_at')->orWhereNotNull('clicked_at'))->count();
        $read = (clone $messages)->where(fn ($q) => $q->whereNotNull('read_at')->orWhereNotNull('clicked_at'))->count();
        $clicked = (clone $messages)->whereNotNull('clicked_at')->count();

        $bookings = $this->attributedBookings($campaign)->get(['id', 'reference', 'client_id', 'total', 'status', 'created_at', 'starts_at']);
        $valid = $bookings->reject(fn (Booking $b) => $b->status->isCancelled() || $b->status === BookingStatus::NoShow);
        $revenue = (int) $valid->sum('total');
        $cost = (int) (clone $messages)->sum('cost');

        return [
            'recipients' => (clone $messages)->count(),
            'skipped_no_consent' => $byStatus['skipped_no_consent'] ?? 0,
            'deferred' => $byStatus['skipped_quiet_hours'] ?? 0,
            'held' => $byStatus['held'] ?? 0,
            'failed' => $byStatus['failed'] ?? 0,
            'sent' => $sent,
            'delivered' => $delivered,
            'read' => $read,
            'clicked' => $clicked,
            'booked' => $valid->count(),
            'revenue' => $revenue,
            'cost' => $cost,
            'roi' => $cost > 0 ? round($revenue / $cost, 1) : null,
            'by_channel' => (clone $messages)->whereNotNull('sent_at')->selectRaw('channel, count(*) as c')->groupBy('channel')->pluck('c', 'channel')->all(),
            'bookings' => $bookings,
            'variants' => $campaign->variants()->get()->map(fn ($v) => [
                'key' => $v->key, 'is_winner' => $v->is_winner,
                'sent' => $v->messages()->whereNotNull('sent_at')->count(),
                'clicked' => $v->messages()->whereNotNull('clicked_at')->count(),
                'booked' => $v->messages()->whereNotNull('booking_id')->count(),
            ])->all(),
        ];
    }

    public function attributedBookings(Campaign $campaign)
    {
        $lastSent = CampaignMessage::where('campaign_id', $campaign->id)->max('sent_at');
        $until = ($lastSent ? Carbon::parse($lastSent) : ($campaign->started_at ?? now()))->copy()->addDays(Campaign::ATTRIBUTION_DAYS);

        return Booking::withoutGlobalScope(BranchScope::class)->where('campaign_id', $campaign->id)
            ->when($campaign->started_at, fn ($q) => $q->where('created_at', '>=', $campaign->started_at->copy()->subMinute()))
            ->where('created_at', '<=', $until)
            ->latest();
    }
}
