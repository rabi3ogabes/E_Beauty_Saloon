<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Booking\Models\Booking;
use App\Domain\CRM\Models\Client;
use App\Domain\Loyalty\Actions\LoyaltyLedger;
use App\Domain\Marketing\Support\LoyaltySettings;

/**
 * Automatic earning when a booking completes. Idempotency key "loyalty:booking:{id}" — the POS module must use the
 * same key when it settles a booking so points are never earned twice (whichever runs first wins).
 * Tier perk "points_multiplier" (e.g. 1.5 for Gold) is credited as a separate bonus entry "loyalty:booking:{id}:tier".
 */
class EarnLoyaltyForBooking
{
    public function __construct(protected LoyaltyLedger $ledger) {}

    public function handle(Booking $booking): int
    {
        // Bookings settled at the POS earn points on the sale (key sale-earn:{id}) — never twice.
        if ($booking->sale_id) {
            return 0;
        }
        if (! tenant()?->hasModule('loyalty') || ! $booking->client_id || (int) $booking->total <= 0 || ! LoyaltySettings::enabled()) {
            return 0;
        }
        $client = Client::find($booking->client_id);
        if (! $client) {
            return 0;
        }
        $tierBefore = $client->loyalty_tier ?: 'silver';
        $entry = $this->ledger->earnForSpend($client, (int) $booking->total, $booking, 'loyalty:booking:'.$booking->id);
        $points = (int) ($entry?->points ?? 0);

        $multiplier = (float) (LoyaltySettings::tier($tierBefore)['points_multiplier'] ?? 1);
        if ($entry && $multiplier > 1) {
            $bonus = (int) floor($points * ($multiplier - 1));
            if ($bonus > 0) {
                $this->ledger->award($client, $bonus, 'bonus', $booking, 'loyalty:booking:'.$booking->id.':tier', __('marketing.loyalty.tier_bonus_note'));
                $points += $bonus;
            }
        }

        return $points;
    }
}
