<?php

namespace App\Domain\Booking\Support;

/**
 * Tenant booking policy (tenant()->settings['booking'][...]) with safe defaults.
 * Fee percents are plain percents (100 = 100%) of the amount collected online (deposit / prepayment).
 */
final class BookingSettings
{
    public const DEFAULTS = [
        'slot_interval' => 15,
        'min_notice_minutes' => 60,
        'max_days_ahead' => 60,
        'cancellation_window_hours' => 24,
        'late_cancel_fee_percent' => 0,
        'no_show_fee_percent' => 100,
        'reschedule_limit' => 2,
        'hold_minutes' => 10,
    ];

    public static function get(string $key): int
    {
        $value = tenant()?->setting('booking.'.$key);

        return (int) ($value ?? self::DEFAULTS[$key] ?? 0);
    }

    public static function slotInterval(): int
    {
        return max(5, self::get('slot_interval'));
    }

    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $out[$key] = self::get($key);
        }

        return $out;
    }
}
