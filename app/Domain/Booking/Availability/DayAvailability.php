<?php

namespace App\Domain\Booking\Availability;

final class DayAvailability
{
    /** @param list<Slot> $slots */
    public function __construct(
        public readonly string $date,
        public readonly array $slots,
        public readonly bool $closed,
        public readonly string $timezone,
        public ?string $nextAvailableDate = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->slots === [];
    }

    public function first(): ?Slot
    {
        return $this->slots[0] ?? null;
    }

    /** @return array{morning: list<Slot>, afternoon: list<Slot>, evening: list<Slot>} */
    public function grouped(): array
    {
        $out = ['morning' => [], 'afternoon' => [], 'evening' => []];
        foreach ($this->slots as $slot) {
            $out[$slot->period($this->timezone)][] = $slot;
        }

        return $out;
    }

    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'closed' => $this->closed,
            'timezone' => $this->timezone,
            'count' => count($this->slots),
            'next_available_date' => $this->nextAvailableDate,
            'periods' => array_map(fn ($slots) => array_map(fn (Slot $s) => $s->toArray($this->timezone), $slots), $this->grouped()),
        ];
    }
}
