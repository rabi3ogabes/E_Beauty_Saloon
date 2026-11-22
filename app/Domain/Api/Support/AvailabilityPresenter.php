<?php

namespace App\Domain\Api\Support;

use App\Domain\Booking\Availability\DayAvailability;
use App\Domain\Booking\Availability\ServiceLine;
use App\Domain\Booking\Availability\Slot;
use App\Domain\Booking\Support\BookingPayments;
use App\Domain\Branches\Models\Branch;
use App\Domain\Team\Models\Staff;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Mobile shape of an engine day:
 * {date, timezone, closed, next_available_date, payment_options, groups: {morning|afternoon|evening: [{starts_at, ends_at, time,
 *  label, price, deposit, duration_minutes, staff_ids, items: [{service_id, variant_id, staff_id, staff_name, starts_at, ends_at, price}]}]}}
 */
class AvailabilityPresenter
{
    public function __construct(protected BookingPayments $payments) {}

    /** @param list<ServiceLine> $lines */
    public function day(Branch $branch, array $lines, DayAvailability $day, bool $withPayment = true): array
    {
        $currency = tenant()?->currency ?? 'QAR';
        $staffIds = collect($day->slots)->flatMap(fn (Slot $s) => $s->staffIds())->unique()->values()->all();
        $names = $staffIds ? Staff::withoutGlobalScopes()->whereIn('id', $staffIds)->get()->mapWithKeys(fn (Staff $s) => [$s->id => (string) $s->name]) : collect();
        $tz = $day->timezone;

        $slot = function (Slot $s) use ($lines, $names, $tz, $currency, $withPayment) {
            $local = $s->startsAt()->setTimezone($tz);

            return [
                'starts_at' => $s->startsAt()->toIso8601ZuluString(),
                'ends_at' => $s->endsAt()->toIso8601ZuluString(),
                'time' => $local->format('H:i'),
                'label' => $local->translatedFormat('g:i A'),
                'price' => Money::of($s->price(), $currency),
                'deposit' => $withPayment ? Money::of($this->payments->depositFor($lines, $s), $currency) : null,
                'duration_minutes' => $s->durationMinutes(),
                'staff_ids' => $s->staffIds(),
                'items' => array_map(fn (array $i) => [
                    'service_id' => $i['service_id'],
                    'variant_id' => $i['variant_id'],
                    'addon_ids' => $i['addon_ids'],
                    'staff_id' => $i['staff_id'],
                    'staff_name' => $names[$i['staff_id']] ?? null,
                    'starts_at' => CarbonImmutable::createFromTimestampUTC($i['starts_at'])->toIso8601ZuluString(),
                    'ends_at' => CarbonImmutable::createFromTimestampUTC($i['ends_at'])->toIso8601ZuluString(),
                    'duration_minutes' => $i['duration_minutes'],
                    'price' => Money::of((int) $i['price'], $currency),
                ], $s->items),
            ];
        };

        $first = $day->first();
        $options = null;
        if ($withPayment && $first) {
            $options = $this->payments->options($first->price(), $this->payments->depositFor($lines, $first), $branch->id);
        }

        return [
            'date' => $day->date,
            'date_label' => CarbonImmutable::parse($day->date, $tz)->translatedFormat('l j F'),
            'timezone' => $tz,
            'closed' => $day->closed,
            'count' => count($day->slots),
            'next_available_date' => $day->nextAvailableDate,
            'payment_options' => $options,
            'groups' => array_map(fn (array $slots) => array_map($slot, $slots), $day->grouped()),
        ];
    }
}
