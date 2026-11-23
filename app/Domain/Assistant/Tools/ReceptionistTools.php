<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Models\Conversation;
use App\Domain\Assistant\Support\AssistantSettings;
use App\Domain\Booking\Actions\CreateBooking;
use App\Domain\Booking\Actions\HoldSlot;
use App\Domain\Booking\Availability\AvailabilityEngine;
use App\Domain\Booking\Availability\LineBuilder;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;

/**
 * The receptionist's tools. Reads are tenant-scoped catalog/availability lookups; booking goes through the SAME
 * booking Actions as the website (HoldSlot → CreateBooking, source "whatsapp_ai"), so every rule (hours, staff,
 * resources, notice, overbooking lock) applies. Results are small arrays the model reads back.
 */
class ReceptionistTools
{
    public function __construct(
        protected AvailabilityEngine $engine,
        protected LineBuilder $lines,
        protected HoldSlot $hold,
        protected CreateBooking $create,
    ) {}

    /** JSON-schema style definitions shown to the model. */
    public function definitions(): array
    {
        return [
            ['name' => 'salon_info', 'description' => 'Salon name, branches (id, name, address, phone), opening hours for the next 7 days and the owner FAQ.', 'parameters' => ['type' => 'object', 'properties' => new \stdClass]],
            ['name' => 'list_services', 'description' => 'Bookable services with id, name, category, duration (minutes) and price. Optional search text.', 'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]],
            ['name' => 'check_availability', 'description' => 'Free start times (salon local time) for a service on a date; also returns the next date with availability.', 'parameters' => ['type' => 'object', 'required' => ['service_id', 'date'], 'properties' => ['service_id' => ['type' => 'integer'], 'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'branch_id' => ['type' => 'integer']]]],
            ['name' => 'book', 'description' => 'Book the service at a free time returned by check_availability. Ask for the client\'s name first if unknown.', 'parameters' => ['type' => 'object', 'required' => ['service_id', 'date', 'time'], 'properties' => ['service_id' => ['type' => 'integer'], 'date' => ['type' => 'string'], 'time' => ['type' => 'string', 'description' => 'HH:MM'], 'branch_id' => ['type' => 'integer'], 'name' => ['type' => 'string']]]],
            ['name' => 'handoff', 'description' => 'Pass the conversation to a human team member (complaints, medical questions, anything you cannot do).', 'parameters' => ['type' => 'object', 'properties' => ['reason' => ['type' => 'string']]]],
        ];
    }

    public function call(string $name, array $args, Conversation $conversation): array
    {
        try {
            return match ($name) {
                'salon_info' => $this->salonInfo($conversation),
                'list_services' => $this->services($conversation, (string) ($args['query'] ?? '')),
                'check_availability' => $this->availability($conversation, $args),
                'book' => $this->book($conversation, $args),
                'handoff' => ['handoff' => true, 'reason' => mb_substr((string) ($args['reason'] ?? 'requested'), 0, 40)],
                default => ['error' => 'unknown tool'],
            };
        } catch (DomainException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    protected function branch(array $args): Branch
    {
        $q = Branch::query()->active()->where('booking_enabled', true)->orderBy('sort');

        return (! empty($args['branch_id']) ? (clone $q)->whereKey((int) $args['branch_id'])->first() : null)
            ?? $q->first() ?? throw new DomainException(__('booking.errors.branch_unavailable'));
    }

    protected function salonInfo(Conversation $c): array
    {
        $locale = $c->locale;
        $days = collect(range(0, 6))->map(fn ($i) => CarbonImmutable::now(tenant()->timezone)->addDays($i));

        return [
            'salon' => tenant()->name,
            'branches' => Branch::query()->active()->orderBy('sort')->get()->map(fn (Branch $b) => [
                'id' => $b->id, 'name' => $b->getTranslation('name', $locale, false) ?: (string) $b->name,
                'address' => $b->getTranslation('address', $locale, false) ?: null, 'phone' => $b->phone,
                'hours' => $days->mapWithKeys(fn ($d) => [$d->locale($locale)->dayName => ($h = $b->hoursFor($d)) ? $h['open'].'–'.$h['close'] : 'closed'])->all(),
            ])->all(),
            'faq' => (string) AssistantSettings::get('faq', ''),
        ];
    }

    protected function services(Conversation $c, string $query): array
    {
        $branch = $this->branch([]);
        $needle = mb_strtolower(trim($query));

        return ['services' => Service::query()->active()->where('online_bookable', true)->with('category')->orderBy('sort')->get()
            ->filter(fn (Service $s) => $s->isActiveAt($branch))
            ->filter(fn (Service $s) => $needle === '' || collect($s->getTranslations('name'))->contains(fn ($n) => str_contains(mb_strtolower($n), $needle)))
            ->take(15)
            ->map(fn (Service $s) => [
                'id' => $s->id, 'name' => $s->getTranslation('name', $c->locale, false) ?: (string) $s->name,
                'category' => $s->category?->getTranslation('name', $c->locale, false),
                'duration_minutes' => $s->durationAt($branch), 'price' => money($s->priceAt($branch)),
            ])->values()->all()];
    }

    protected function availability(Conversation $c, array $args): array
    {
        $branch = $this->branch($args);
        $lines = $this->lines->build($branch, [['service_id' => (int) ($args['service_id'] ?? 0)]], true);
        $date = $this->date($args['date'] ?? null, $branch);
        $day = $this->engine->dayWithNext($branch, $lines, $date, ['online' => true]);
        $tz = $branch->timezone;
        $times = array_map(fn ($s) => $s->startsAt()->setTimezone($tz)->format('H:i'), $day->slots);

        return [
            'branch_id' => $branch->id, 'date' => $day->date, 'closed' => $day->closed,
            'times' => $this->spread($times, 8), 'total_free_times' => count($times),
            'next_available_date' => $day->nextAvailableDate,
        ];
    }

    protected function book(Conversation $c, array $args): array
    {
        $branch = $this->branch($args);
        if (! preg_match('/^\d{1,2}:\d{2}$/', (string) ($args['time'] ?? ''))) {
            return ['error' => 'time must be HH:MM'];
        }
        $start = CarbonImmutable::parse($this->date($args['date'] ?? null, $branch).' '.$args['time'], $branch->timezone)->utc();
        $client = $c->client ?? Client::query()->where('phone', $c->phone)->first();
        if (! $client) {
            $name = trim((string) ($args['name'] ?? $c->contact_name ?? ''));
            if ($name === '') {
                return ['error' => 'need_client_name'];
            }
            $client = Client::create(['name' => mb_substr($name, 0, 120), 'phone' => $c->phone, 'source' => 'whatsapp', 'locale' => $c->locale]);
        }
        $c->update(['client_id' => $client->id]);

        $items = [['service_id' => (int) ($args['service_id'] ?? 0)]];
        $hold = $this->hold->handle($branch, $items, $start, online: true);
        $booking = $this->create->handle([
            'branch' => $branch, 'items' => $items, 'starts_at' => $start, 'client' => $client,
            'source' => 'whatsapp_ai', 'online' => false, 'enforce_notice' => true,
            'payment_preference' => 'pay_at_salon', 'hold_token' => $hold['token'],
            'internal_notes' => __('insights.assistant.booked_note', [], $c->locale),
        ]);
        $this->hold->release($hold['token']);
        $c->update(['last_booking_id' => $booking->id]);
        $booking->load('items.service', 'items.staff');

        return [
            'booked' => true, 'reference' => $booking->reference,
            'starts_at' => $booking->starts_at->copy()->setTimezone($branch->timezone)->format('Y-m-d H:i'),
            'service' => (string) $booking->items->first()?->service?->getTranslation('name', $c->locale, false),
            'with' => (string) $booking->items->first()?->staff?->name,
            'branch' => (string) $branch->name, 'total' => money($booking->total),
        ];
    }

    protected function date(mixed $value, Branch $branch): string
    {
        $today = CarbonImmutable::now($branch->timezone)->startOfDay();
        $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? CarbonImmutable::parse($value, $branch->timezone) : $today;

        return $date->lt($today) ? $today->toDateString() : $date->toDateString();
    }

    /** Up to $n evenly spread times so the reply stays short. */
    protected function spread(array $times, int $n): array
    {
        if (count($times) <= $n) {
            return $times;
        }
        $step = count($times) / $n;

        return array_values(array_map(fn ($i) => $times[(int) floor($i * $step)], range(0, $n - 1)));
    }
}
