<?php

namespace App\Domain\Booking\Availability;

use Carbon\CarbonImmutable;

/**
 * A bookable start time with the proposed assignment of every service (staff, resources, times).
 * All timestamps are UTC unix seconds; format in the branch timezone for display.
 */
final class Slot
{
    /**
     * @param  list<array{line:int,service_id:int,variant_id:?int,addon_ids:list<int>,staff_id:int,resource_id:?int,resource_ids:list<int>,starts_at:int,ends_at:int,buffer_before:int,buffer_after:int,price:int,duration_minutes:int}>  $items
     */
    public function __construct(
        public readonly int $start,
        public readonly int $end,
        public readonly array $items,
    ) {}

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($this->start);
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($this->end);
    }

    public function price(): int
    {
        return (int) array_sum(array_column($this->items, 'price'));
    }

    public function durationMinutes(): int
    {
        return intdiv($this->end - $this->start, 60);
    }

    public function staffIds(): array
    {
        return array_values(array_unique(array_column($this->items, 'staff_id')));
    }

    public function period(string $tz): string
    {
        $hour = (int) $this->startsAt()->setTimezone($tz)->format('G');

        return $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');
    }

    public function toArray(string $tz): array
    {
        $local = $this->startsAt()->setTimezone($tz);

        return [
            'starts_at' => $this->startsAt()->toIso8601ZuluString(),
            'ends_at' => $this->endsAt()->toIso8601ZuluString(),
            'time' => $local->format('H:i'),
            'label' => $local->translatedFormat('g:i A'),
            'period' => $this->period($tz),
            'price' => $this->price(),
            'duration' => $this->durationMinutes(),
            'staff_ids' => $this->staffIds(),
            'items' => array_map(fn ($i) => array_merge($i, [
                'starts_at' => CarbonImmutable::createFromTimestampUTC($i['starts_at'])->toIso8601ZuluString(),
                'ends_at' => CarbonImmutable::createFromTimestampUTC($i['ends_at'])->toIso8601ZuluString(),
            ]), $this->items),
        ];
    }

    /** Compact (cache) form. */
    public function pack(): array
    {
        return [$this->start, $this->end, $this->items];
    }

    public static function unpack(array $p): self
    {
        return new self($p[0], $p[1], $p[2]);
    }
}
