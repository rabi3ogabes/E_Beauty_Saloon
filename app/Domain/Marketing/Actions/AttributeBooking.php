<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignMessage;

/**
 * Revenue attribution: a booking carrying campaign_id (set by the Booking module from ?campaign=ID, or here from
 * the remembered click) is linked to the recipient's campaign_message when it was created within 7 days of the send.
 */
class AttributeBooking
{
    /** Booking::creating — fill campaign_id from the remembered click when the Booking module did not. */
    public function fill(Booking $booking): void
    {
        if (! $booking->campaign_id && ($campaignId = TrackCampaignClick::rememberedCampaignId($booking->client_id))
            && Campaign::whereKey($campaignId)->exists()) {
            $booking->campaign_id = $campaignId;
            $booking->utm_source ??= 'campaign';
        }
    }

    /** Booking::created / updated — link the booking to the recipient message and keep revenue in sync. */
    public function link(Booking $booking): void
    {
        if (! $booking->campaign_id || ! $booking->client_id) {
            return;
        }
        $message = CampaignMessage::where('campaign_id', $booking->campaign_id)->where('client_id', $booking->client_id)->first();
        if (! $message || ! $message->sent_at) {
            return;
        }
        if ($message->booking_id && $message->booking_id !== $booking->id) {
            return; // first attributed booking wins
        }
        $created = $booking->created_at ?? now();
        if ($created->gt($message->sent_at->copy()->addDays(Campaign::ATTRIBUTION_DAYS))) {
            return;
        }
        $cancelled = $booking->status instanceof BookingStatus && ($booking->status->isCancelled() || $booking->status === BookingStatus::NoShow);
        $message->update([
            'booking_id' => $booking->id,
            'booked_at' => $message->booked_at ?? $created,
            'revenue' => $cancelled ? 0 : (int) $booking->total,
        ]);
    }
}
