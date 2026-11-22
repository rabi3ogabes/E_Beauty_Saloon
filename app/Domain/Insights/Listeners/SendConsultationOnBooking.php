<?php

namespace App\Domain\Insights\Listeners;

use App\Domain\Booking\Events\BookingCreated;
use App\Domain\Insights\Actions\SendConsultationForm;
use App\Domain\Insights\Support\InsightSettings;

/** Opt-in: send the consultation form after a booking when the client has no valid signed form. */
class SendConsultationOnBooking
{
    public function handle(BookingCreated $event): void
    {
        $booking = $event->booking;
        if (! tenant() || tenant()->id !== (int) $booking->tenant_id || ! InsightSettings::get('consultation.auto_send', false) || ! $booking->client) {
            return;
        }
        try {
            if (! SendConsultationForm::hasValidForm($booking->client)) {
                app(SendConsultationForm::class)->handle($booking->client, $booking);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
