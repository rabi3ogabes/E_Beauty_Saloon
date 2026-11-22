<?php

namespace App\Domain\Shop\Support;

use App\Domain\Tenancy\Models\Tenant;

/**
 * Shop settings, stored under tenants.settings["shop"]. Money in minor units.
 */
class ShopSettings
{
    public const DEFAULTS = [
        'cod_enabled' => true,
        'cod_limit' => 200000,          // max outstanding COD per client (minor units), 0 = no limit
        'cod_otp_required' => false,    // COD orders confirm only after the client enters an OTP
        'gift_wrap_enabled' => true,
        'gift_wrap_price' => 2500,
        'same_day_cutoff' => '14:00',   // default same-day cutoff (branch local time) when a zone has none
        'pickup_prep_minutes' => 120,   // ready-time estimate for pickup
        'hide_out_of_stock' => false,   // low-stock hiding: hide products with no available stock
        'low_stock_badge' => 3,         // show "only N left" at or below this
        'reservation_minutes' => 30,    // unpaid online orders are released after this
        'abandoned_after_minutes' => 120,
    ];

    public static function all(?Tenant $tenant = null): array
    {
        $tenant ??= tenant();

        return array_replace(self::DEFAULTS, (array) ($tenant?->setting('shop') ?? []));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function save(array $values, ?Tenant $tenant = null): void
    {
        $tenant ??= tenant();
        $settings = $tenant->settings ?? [];
        $settings['shop'] = array_replace(self::all($tenant), array_intersect_key($values, self::DEFAULTS));
        $tenant->forceFill(['settings' => $settings])->save();
    }
}
