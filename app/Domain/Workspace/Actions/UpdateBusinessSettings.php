<?php

namespace App\Domain\Workspace\Actions;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Booking policies (settings.booking.*) and loyalty rules (settings.loyalty.*).
 * Unknown keys are ignored; numbers are clamped to sane ranges.
 */
class UpdateBusinessSettings
{
    public const BOOKING = [
        'slot_interval' => [5, 120], 'min_notice_minutes' => [0, 10080], 'max_days_ahead' => [1, 365],
        'cancellation_window_hours' => [0, 168], 'late_cancel_fee_percent' => [0, 100], 'no_show_fee_percent' => [0, 100],
        'reschedule_limit' => [0, 20], 'hold_minutes' => [1, 60],
    ];

    public function handle(Tenant $tenant, array $booking = [], array $loyalty = []): Tenant
    {
        abort_if($tenant->isReadOnly(), 423, __('salon.errors.read_only'));

        $settings = $tenant->settings ?? [];
        foreach (self::BOOKING as $key => [$min, $max]) {
            if (array_key_exists($key, $booking) && $booking[$key] !== null && $booking[$key] !== '') {
                $settings['booking'][$key] = max($min, min($max, (int) $booking[$key]));
            }
        }

        if (array_key_exists('points_per_currency_unit', $loyalty)) {
            $settings['loyalty']['points_per_currency_unit'] = max(0, min(100, (float) $loyalty['points_per_currency_unit']));
        }
        if (array_key_exists('point_value', $loyalty)) {
            $settings['loyalty']['point_value'] = max(0, (int) $loyalty['point_value']); // minor units per point
        }
        if (array_key_exists('tiers', $loyalty)) {
            $settings['loyalty']['tiers'] = collect($loyalty['tiers'] ?? [])
                ->filter(fn ($t) => filled($t['key'] ?? null))
                ->map(fn ($t) => ['key' => Str::slug($t['key'], '_'), 'min_points' => max(0, (int) ($t['min_points'] ?? 0))])
                ->sortBy('min_points')->values()->all();
        }

        $tenant->update(['settings' => $settings]);
        activity()->performedOn($tenant)->causedBy(auth()->user())->log('settings.business_updated');

        return $tenant;
    }
}
