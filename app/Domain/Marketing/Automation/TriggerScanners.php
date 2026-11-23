<?php

namespace App\Domain\Marketing\Automation;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Models\Automation;
use App\Domain\Marketing\Models\ClientMembership;
use App\Domain\Marketing\Models\ClientPackage;
use App\Support\Money\Money;
use App\Support\Tenancy\BranchScope;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Daily scanners for time-based triggers. Each yields [Client, context] — context['dedupe'] keeps runs idempotent. */
final class TriggerScanners
{
    protected static function today()
    {
        return now(tenant()?->timezone ?? 'UTC');
    }

    public static function birthday(Automation $automation): Generator
    {
        $today = self::today();
        foreach (Client::whereNotNull('birthday')->whereMonth('birthday', $today->month)->whereDay('birthday', $today->day)->with('consents')->cursor() as $client) {
            yield [$client, ['dedupe' => 'birthday:'.$today->year]];
        }
    }

    public static function winBack(Automation $automation): Generator
    {
        $days = (int) $automation->config('days', 60);
        $query = Client::whereNotNull('last_visit_at')
            ->where('last_visit_at', '<=', now()->subDays($days))
            ->where('last_visit_at', '>', now()->subDays($days + 30))
            ->with('consents');
        foreach ($query->cursor() as $client) {
            yield [$client, ['dedupe' => 'winback:'.$client->last_visit_at->toDateString()]];
        }
    }

    public static function rebookDue(Automation $automation): Generator
    {
        $rows = DB::table('bookings')
            ->join('booking_items', 'booking_items.booking_id', '=', 'bookings.id')
            ->join('services', 'services.id', '=', 'booking_items.service_id')
            ->where('bookings.tenant_id', tenant()->id)
            ->where('bookings.status', BookingStatus::Completed->value)
            ->whereNotNull('services.rebook_after_days')
            ->whereNotNull('bookings.client_id')
            ->where('bookings.completed_at', '>=', now()->subDays(400))
            ->select(['bookings.id as booking_id', 'bookings.client_id', 'bookings.completed_at', 'services.rebook_after_days', 'services.name as service_name'])
            ->orderByDesc('bookings.completed_at')
            ->get();

        $seen = [];
        foreach ($rows as $row) {
            if (isset($seen[$row->client_id])) {
                continue; // only the latest visit per client
            }
            $seen[$row->client_id] = true;
            $due = Carbon::parse($row->completed_at)->addDays((int) $row->rebook_after_days);
            if (! $due->between(now()->subDays(3), now())) {
                continue;
            }
            $hasUpcoming = Booking::withoutGlobalScope(BranchScope::class)->where('client_id', $row->client_id)->where('starts_at', '>', now())
                ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::PendingPayment->value])->exists();
            $client = $hasUpcoming ? null : Client::with('consents')->find($row->client_id);
            if ($client) {
                $name = json_decode((string) $row->service_name, true);
                $service = is_array($name) ? ($name[$client->locale] ?? $name['en'] ?? reset($name)) : $row->service_name;
                yield [$client, ['dedupe' => 'rebook:'.$row->booking_id, 'service' => $service]];
            }
        }
    }

    public static function lowWallet(Automation $automation): Generator
    {
        $below = Money::fromDecimal((string) $automation->config('below', 50), tenant()?->currency ?? 'QAR')->amount;
        foreach (Client::where('wallet_balance', '>', 0)->where('wallet_balance', '<', $below)->with('consents')->cursor() as $client) {
            yield [$client, ['dedupe' => 'lowwallet:'.now()->format('Y-m'), 'balance' => money((int) $client->wallet_balance)]];
        }
    }

    public static function packageExpiring(Automation $automation): Generator
    {
        $days = (int) $automation->config('days_before', 14);
        $packages = ClientPackage::with(['client.consents', 'package'])->where('status', 'active')->where('sessions_remaining', '>', 0)
            ->whereBetween('expires_at', [now(), now()->addDays($days)])->get();
        foreach ($packages as $cp) {
            if (! $cp->client) {
                continue;
            }
            $cp->forceFill(['expiry_reminded_at' => now()])->saveQuietly();
            yield [$cp->client, [
                'dedupe' => 'package:'.$cp->id, 'sessions' => $cp->sessions_remaining,
                'package' => $cp->package?->getTranslation('name', $cp->client->locale ?: 'en'), 'expires' => $cp->expires_at->translatedFormat('j M Y'),
            ]];
        }
    }

    public static function membershipRenewal(Automation $automation): Generator
    {
        $days = (int) $automation->config('days_before', 3);
        $memberships = ClientMembership::with(['client.consents', 'membership'])->where('status', 'active')
            ->whereBetween('renews_at', [now(), now()->addDays($days)])->get();
        foreach ($memberships as $cm) {
            if (! $cm->client) {
                continue;
            }
            yield [$cm->client, [
                'dedupe' => 'membership:'.$cm->id.':'.$cm->renews_at->toDateString(),
                'membership' => $cm->membership?->getTranslation('name', $cm->client->locale ?: 'en'), 'renews' => $cm->renews_at->translatedFormat('j M Y'),
            ]];
        }
    }

    public static function bookingUpcoming(Automation $automation): Generator
    {
        $hours = (int) $automation->config('hours_before', 24);
        $bookings = Booking::withoutGlobalScope(BranchScope::class)->with(['client.consents', 'branch'])
            ->where('status', BookingStatus::Confirmed->value)->whereNotNull('client_id')
            ->whereBetween('starts_at', [now()->addHours($hours)->subHours(24), now()->addHours($hours)])->get();
        foreach ($bookings as $booking) {
            if ($booking->client) {
                yield [$booking->client, ['dedupe' => 'reminder:'.$booking->id, 'when' => $booking->localStart()->translatedFormat('l j M, H:i'), 'reference' => $booking->reference]];
            }
        }
    }
}
