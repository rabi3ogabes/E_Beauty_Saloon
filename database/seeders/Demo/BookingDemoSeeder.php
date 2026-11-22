<?php

namespace Database\Seeders\Demo;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\BookingStatusHistory;
use App\Domain\Booking\Models\WaitlistEntry;
use App\Domain\Branches\Models\Branch;
use App\Domain\CRM\Models\Client;
use App\Domain\Team\Models\Staff;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * ~120 realistic bookings for the demo salon "lumiere": the last 60 days (mostly completed, a few no-shows and
 * cancellations) and the next 14 days (confirmed, a few awaiting deposit), respecting staff schedules and
 * never double-booking a stylist. Written directly (no messages are sent while seeding).
 */
class BookingDemoSeeder extends Seeder
{
    public static int $order = 20;

    /** @var array<int, list<array{0:int,1:int}>> */
    protected array $busy = [];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'lumiere')->first();
        if (! $tenant) {
            return;
        }

        app(TenantContext::class)->run($tenant, function () {
            if (Booking::query()->exists()) {
                return;
            }
            mt_srand(2026);
            $branches = Branch::query()->with('holidays')->orderBy('sort')->get();
            $clients = Client::query()->inRandomOrder()->limit(150)->get();
            $staff = Staff::query()->withoutGlobalScope('branch_visibility')->with(['services', 'schedules', 'branches'])->where('is_active', true)->get();
            if ($clients->isEmpty() || $staff->isEmpty()) {
                return;
            }

            $created = 0;
            for ($offset = -60; $offset <= 14; $offset++) {
                $perDay = $offset < 0 ? mt_rand(1, 2) : mt_rand(1, 3);
                for ($n = 0; $n < $perDay; $n++) {
                    if ($this->createOne($branches, $staff, $clients, $offset)) {
                        $created++;
                    }
                }
            }

            // A couple of waitlist entries for the coming days.
            foreach ($clients->take(2) as $i => $client) {
                $branch = $branches->first();
                WaitlistEntry::create([
                    'branch_id' => $branch->id, 'client_id' => $client->id, 'service_ids' => [$staff->first()->services->first()?->id],
                    'date_from' => now($branch->timezone)->addDays(2 + $i)->toDateString(), 'date_to' => now($branch->timezone)->addDays(4 + $i)->toDateString(),
                    'time_window' => $i ? 'evening' : 'any',
                ]);
            }

            $this->command?->info("Booking demo: {$created} bookings");
        });
    }

    protected function createOne($branches, $staff, $clients, int $offset): bool
    {
        $branch = $branches->random();
        $tz = $branch->timezone;
        $date = CarbonImmutable::now($tz)->startOfDay()->addDays($offset);
        if (! ($hours = $branch->hoursFor($date))) {
            return false;
        }
        $working = $staff->filter(fn ($s) => $s->branches->contains('id', $branch->id)
            && $s->schedules->where('branch_id', $branch->id)->where('weekday', $date->dayOfWeek)->isNotEmpty())->values();
        if ($working->isEmpty()) {
            return false;
        }
        $member = $working->random();
        $schedule = $member->schedules->where('branch_id', $branch->id)->firstWhere('weekday', $date->dayOfWeek);
        $services = $member->services->where('is_active', true)->values();
        if ($services->isEmpty()) {
            return false;
        }
        $picked = $services->random(min($services->count(), mt_rand(1, 10) > 8 ? 2 : 1));
        $picked = $picked instanceof Collection ? $picked->values() : collect([$picked]);

        $open = max($this->minutes($hours['open']), $this->minutes((string) $schedule->starts_at));
        $close = min($this->minutes($hours['close']), $this->minutes((string) $schedule->ends_at));
        $duration = (int) $picked->sum(fn ($s) => $s->durationAt($branch, $member));
        $buffer = (int) $picked->last()->buffer_after;

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $startMin = $open + mt_rand(0, max(0, intdiv($close - $open - $duration - $buffer, 30))) * 30;
            if ($startMin + $duration > $close || $this->overlapsBreak($schedule->breaks ?? [], $startMin, $startMin + $duration)) {
                continue;
            }
            $start = $date->addMinutes($startMin)->utc();
            $end = $start->addMinutes($duration);
            if ($this->isBusy($member->id, $start->getTimestamp(), $end->getTimestamp() + $buffer * 60)) {
                continue;
            }
            $this->busy[$member->id][] = [$start->getTimestamp(), $end->getTimestamp() + $buffer * 60];
            $this->persist($branch, $member, $picked, $clients->random(), $start, $end, $offset);

            return true;
        }

        return false;
    }

    protected function persist(Branch $branch, Staff $member, $services, Client $client, CarbonImmutable $start, CarbonImmutable $end, int $offset): void
    {
        $roll = mt_rand(1, 100);
        $status = $offset < 0
            ? ($roll <= 82 ? BookingStatus::Completed : ($roll <= 90 ? BookingStatus::NoShow : ($roll <= 96 ? BookingStatus::CancelledByClient : BookingStatus::CancelledBySalon)))
            : ($offset === 0 && $start->isPast() ? BookingStatus::CheckedIn : ($roll <= 88 ? BookingStatus::Confirmed : BookingStatus::PendingPayment));

        $prices = $services->map(fn ($s) => $s->priceAt($branch, $member));
        $total = (int) $prices->sum();
        $deposit = (int) $services->values()->map(fn ($s, $i) => $s->depositFor($prices[$i]))->sum();
        $paid = $deposit > 0 && ! in_array($status, [BookingStatus::PendingPayment, BookingStatus::CancelledBySalon], true) ? $deposit : 0;
        $source = ['website', 'website', 'website', 'app', 'dashboard', 'phone', 'walk_in', 'whatsapp_ai'][mt_rand(0, 7)];
        $createdAt = $status === BookingStatus::PendingPayment ? CarbonImmutable::now() : $start->subDays(mt_rand(1, 12))->min(now());

        $booking = Booking::create([
            'branch_id' => $branch->id, 'client_id' => $client->id, 'status' => $status, 'source' => $source,
            'starts_at' => $start, 'ends_at' => $end, 'subtotal' => $total, 'total' => $total,
            'deposit_required' => $deposit, 'amount_paid' => $status === BookingStatus::NoShow ? $paid : ($status->isCancelled() ? 0 : $paid),
            'fee_charged' => $status === BookingStatus::NoShow ? $paid : 0,
            'payment_preference' => $deposit > 0 ? 'deposit' : 'pay_at_salon',
            'utm_source' => mt_rand(1, 10) > 8 ? ['instagram', 'tiktok', 'google'][mt_rand(0, 2)] : null,
            'confirmed_at' => $status === BookingStatus::PendingPayment ? null : $createdAt,
            'checked_in_at' => in_array($status, [BookingStatus::CheckedIn, BookingStatus::Completed], true) ? $start->subMinutes(5) : null,
            'completed_at' => $status === BookingStatus::Completed ? $end : null,
            'cancelled_at' => $status->isCancelled() ? $start->subHours(mt_rand(3, 72)) : null,
            'cancellation_reason' => $status->isCancelled() ? 'Schedule change' : null,
            'reminder_24h_sent_at' => $offset < 0 ? $start->subDay() : null,
            'reminder_2h_sent_at' => $offset < 0 ? $start->subHours(2) : null,
            'review_requested_at' => $status === BookingStatus::Completed && $offset < -1 ? $end->addHours(2) : null,
        ]);
        $booking->forceFill(['created_at' => $createdAt])->save();

        $cursor = $start;
        foreach ($services->values() as $i => $service) {
            $minutes = $service->durationAt($branch, $member);
            $booking->items()->create([
                'service_id' => $service->id, 'staff_id' => $member->id, 'starts_at' => $cursor, 'ends_at' => $cursor->addMinutes($minutes),
                'buffer_before' => (int) $service->buffer_before, 'buffer_after' => (int) $service->buffer_after,
                'price' => $prices[$i], 'duration_minutes' => $minutes, 'sort' => $i,
            ]);
            $cursor = $cursor->addMinutes($minutes);
        }

        $history = [[null, $status === BookingStatus::PendingPayment ? BookingStatus::PendingPayment : BookingStatus::Confirmed]];
        $chain = match ($status) {
            BookingStatus::CheckedIn => [BookingStatus::CheckedIn],
            BookingStatus::Completed => [BookingStatus::CheckedIn, BookingStatus::InService, BookingStatus::Completed],
            BookingStatus::NoShow, BookingStatus::CancelledByClient, BookingStatus::CancelledBySalon => [$status],
            default => [],
        };
        $prev = $history[0][1];
        foreach ($chain as $next) {
            $history[] = [$prev, $next];
            $prev = $next;
        }
        foreach ($history as [$from, $to]) {
            BookingStatusHistory::create(['booking_id' => $booking->id, 'from_status' => $from?->value, 'to_status' => $to->value, 'note' => $from ? null : 'Booked via '.$source]);
        }
    }

    protected function isBusy(int $staffId, int $from, int $to): bool
    {
        foreach ($this->busy[$staffId] ?? [] as [$s, $e]) {
            if ($s < $to && $e > $from) {
                return true;
            }
        }

        return false;
    }

    protected function overlapsBreak(array $breaks, int $from, int $to): bool
    {
        foreach ($breaks as $b) {
            if (is_array($b) && count($b) === 2 && $this->minutes($b[0]) < $to && $this->minutes($b[1]) > $from) {
                return true;
            }
        }

        return false;
    }

    protected function minutes(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($hm, 0, 5)));

        return $h * 60 + $m;
    }
}
