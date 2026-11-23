<?php

namespace App\Domain\Branches\Actions;

use App\Domain\Branches\Models\Branch;

/**
 * Converts the weekly hours editor state (list of rows) to the stored JSON shape and back:
 * {"mon":{"open":"10:00","close":"22:00","closed":false,"breaks":[["14:00","15:00"]]},...}
 */
class NormalizeOpeningHours
{
    /** @param  array<int|string, array>  $rows  editor rows: [day, open, close, closed, breaks:[{from,to}]] */
    public function handle(array $rows): array
    {
        $out = Branch::defaultHours();
        foreach ($rows as $key => $row) {
            $day = $row['day'] ?? (is_string($key) ? $key : null);
            if (! in_array($day, Branch::WEEKDAYS, true)) {
                continue;
            }
            $breaks = collect($row['breaks'] ?? [])
                ->map(fn ($b) => isset($b['from'], $b['to']) ? [substr((string) $b['from'], 0, 5), substr((string) $b['to'], 0, 5)] : (array_is_list((array) $b) ? array_values((array) $b) : null))
                ->filter(fn ($b) => is_array($b) && count($b) === 2 && $b[0] && $b[1] && $b[0] < $b[1])
                ->values()->all();

            $out[$day] = [
                'open' => substr((string) ($row['open'] ?? '10:00'), 0, 5),
                'close' => substr((string) ($row['close'] ?? '22:00'), 0, 5),
                'closed' => (bool) ($row['closed'] ?? false),
                'breaks' => $breaks,
            ];
        }

        return $out;
    }

    /** Stored JSON -> editor rows (ordered Sunday..Saturday). */
    public static function toRows(?array $hours): array
    {
        $hours = array_replace(Branch::defaultHours(), $hours ?? []);

        return collect(Branch::WEEKDAYS)->map(fn ($d) => [
            'day' => $d,
            'open' => $hours[$d]['open'] ?? '10:00',
            'close' => $hours[$d]['close'] ?? '22:00',
            'closed' => (bool) ($hours[$d]['closed'] ?? false),
            'breaks' => collect($hours[$d]['breaks'] ?? [])->map(fn ($b) => ['from' => $b[0] ?? null, 'to' => $b[1] ?? null])->all(),
        ])->all();
    }
}
