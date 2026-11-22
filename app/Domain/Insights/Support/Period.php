<?php

namespace App\Domain\Insights\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A reporting window: local calendar dates [from, until] (inclusive) in a timezone, exposed as UTC bounds
 * for queries. Grouping by day/hour is done in PHP with the same timezone, so reports behave identically on
 * SQLite and MySQL (no DB date functions).
 */
final class Period
{
    public function __construct(
        public readonly CarbonImmutable $from,   // local start of day
        public readonly CarbonImmutable $until,  // local end of day
        public readonly string $timezone,
    ) {}

    public static function tz(): string
    {
        return tenant()?->timezone ?? config('app.timezone');
    }

    public static function between(CarbonInterface|string $from, CarbonInterface|string $until, ?string $tz = null): self
    {
        $tz ??= self::tz();
        $f = CarbonImmutable::parse($from instanceof CarbonInterface ? $from->format('Y-m-d') : $from, $tz)->startOfDay();
        $u = CarbonImmutable::parse($until instanceof CarbonInterface ? $until->format('Y-m-d') : $until, $tz)->endOfDay();
        if ($u->lt($f)) {
            [$f, $u] = [$u->startOfDay(), $f->endOfDay()];
        }

        return new self($f, $u, $tz);
    }

    public static function day(CarbonInterface|string $date, ?string $tz = null): self
    {
        return self::between($date, $date, $tz);
    }

    /** Presets: today, yesterday, last_7, last_28, last_30, last_90, this_week, this_month, last_month, this_year, YYYY-MM. */
    public static function preset(string $preset, ?string $tz = null, ?CarbonInterface $now = null): self
    {
        $tz ??= self::tz();
        $now = CarbonImmutable::instance($now ?? now())->setTimezone($tz);

        if (preg_match('/^(\d{4})-(\d{2})$/', $preset, $m)) {
            $start = CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, $tz);

            return self::between($start, $start->endOfMonth(), $tz);
        }

        return match ($preset) {
            'today' => self::between($now, $now, $tz),
            'yesterday' => self::between($now->subDay(), $now->subDay(), $tz),
            'last_7' => self::between($now->subDays(6), $now, $tz),
            'last_28' => self::between($now->subDays(27), $now, $tz),
            'last_90' => self::between($now->subDays(89), $now, $tz),
            'this_week' => self::between($now->startOfWeek(), $now, $tz),
            'last_month' => self::between($now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth(), $tz),
            'this_year' => self::between($now->startOfYear(), $now, $tz),
            'this_month' => self::between($now->startOfMonth(), $now, $tz),
            default => self::between($now->subDays(29), $now, $tz),
        };
    }

    public function fromUtc(): CarbonImmutable
    {
        return $this->from->utc();
    }

    public function untilUtc(): CarbonImmutable
    {
        return $this->until->utc();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function utcBounds(): array
    {
        return [$this->fromUtc(), $this->untilUtc()];
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->until->startOfDay()) + 1;
    }

    /** The same-length window immediately before this one. */
    public function previous(): self
    {
        $days = $this->days();

        return self::between($this->from->subDays($days), $this->from->subDay(), $this->timezone);
    }

    /** @return list<string> local Y-m-d dates */
    public function dates(): array
    {
        $out = [];
        for ($d = $this->from; $d->lte($this->until); $d = $d->addDay()) {
            $out[] = $d->format('Y-m-d');
        }

        return $out;
    }

    public function localDate(CarbonInterface|string|null $utc): ?string
    {
        return $utc === null ? null : CarbonImmutable::parse($utc, 'UTC')->setTimezone($this->timezone)->format('Y-m-d');
    }

    public function label(): string
    {
        return $this->from->isSameDay($this->until)
            ? $this->from->translatedFormat('j M Y')
            : $this->from->translatedFormat('j M').' – '.$this->until->translatedFormat('j M Y');
    }

    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'until' => $this->until->toDateString(), 'timezone' => $this->timezone];
    }
}
