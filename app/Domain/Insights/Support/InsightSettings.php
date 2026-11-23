<?php

namespace App\Domain\Insights\Support;

use App\Domain\Tenancy\Models\Tenant;

/**
 * Tenant settings for the smart layer, stored under tenants.settings.insights (merged over defaults).
 * Money values are minor units.
 */
class InsightSettings
{
    public const DEFAULTS = [
        // Multi-branch client sharing: when off, branch-restricted users only see clients of their branches.
        'client_sharing' => true,
        'ai_copy' => true,
        'alerts' => [
            'big_sale' => true, 'vip_checkin' => true, 'bad_review' => true, 'low_stock' => true, 'failed_payment' => true,
            'whatsapp' => false, 'whatsapp_to' => null, 'big_sale_threshold' => 100000,
        ],
        'morning_report' => ['enabled' => false, 'email' => true, 'whatsapp' => false, 'time' => '08:00'],
        'targets' => ['daily_default' => null, 'daily' => []],
        'consultation' => ['auto_send' => false, 'valid_months' => 12],
    ];

    public static function all(?Tenant $tenant = null): array
    {
        $tenant ??= tenant();

        return array_replace_recursive(self::DEFAULTS, (array) ($tenant?->setting('insights') ?? []));
    }

    public static function get(string $key, mixed $default = null, ?Tenant $tenant = null): mixed
    {
        return data_get(self::all($tenant), $key, $default);
    }

    public static function put(array $values, ?Tenant $tenant = null): void
    {
        $tenant ??= tenant();
        $settings = $tenant->settings ?? [];
        $settings['insights'] = array_replace_recursive((array) ($settings['insights'] ?? []), $values);
        $tenant->forceFill(['settings' => $settings])->save();
    }
}
