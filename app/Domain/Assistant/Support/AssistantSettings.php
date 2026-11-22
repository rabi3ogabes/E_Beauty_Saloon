<?php

namespace App\Domain\Assistant\Support;

use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * WhatsApp AI receptionist settings (tenants.settings.assistant). Off by default: the owner switches it on.
 * hours: the AI answers only inside [from, to) salon-local time; outside, messages wait in the inbox.
 */
class AssistantSettings
{
    public const DEFAULTS = [
        'enabled' => false,
        'hours_from' => '00:00',
        'hours_to' => '24:00',
        'faq' => '',
        'tone' => 'warm, elegant and concise',
        'min_confidence' => 0.5,
        'handoff_keywords' => ['agent', 'human', 'person', 'staff', 'manager', 'موظف', 'موظفة', 'انسان', 'إنسان', 'المدير'],
    ];

    public static function all(?Tenant $tenant = null): array
    {
        $tenant ??= tenant();

        return array_replace(self::DEFAULTS, (array) ($tenant?->setting('assistant') ?? []));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function put(array $values, ?Tenant $tenant = null): void
    {
        $tenant ??= tenant();
        $settings = $tenant->settings ?? [];
        $settings['assistant'] = array_replace((array) ($settings['assistant'] ?? []), $values);
        $tenant->forceFill(['settings' => $settings])->save();
    }

    /** Is the AI on duty right now (owner switch + hours)? */
    public static function onDuty(?CarbonImmutable $now = null): bool
    {
        $s = self::all();
        if (! $s['enabled']) {
            return false;
        }
        $time = ($now ?? CarbonImmutable::now())->setTimezone(tenant()?->timezone ?? 'UTC')->format('H:i');
        $from = (string) $s['hours_from'];
        $to = (string) $s['hours_to'];

        return $from <= $to ? ($time >= $from && $time < $to) : ($time >= $from || $time < $to); // overnight windows
    }
}
