<?php

namespace App\Domain\Insights\Support;

/** Permission checks for the smart layer (owners pass every gate). */
class InsightsGate
{
    public static function reports(): bool
    {
        return (bool) tenant() && (auth()->user()?->can('reports.view') ?? false);
    }

    public static function revenue(): bool
    {
        return self::reports() && (auth()->user()?->can('reports.revenue') ?? false);
    }

    public static function export(): bool
    {
        return self::reports() && (auth()->user()?->can('reports.export') ?? false);
    }

    public static function settings(): bool
    {
        return (bool) tenant() && (auth()->user()?->can('settings.manage') ?? false);
    }

    public static function inbox(): bool
    {
        return (bool) tenant() && (auth()->user()?->can('clients.manage') ?? false);
    }

    public static function ai(): bool
    {
        return (bool) tenant()?->hasModule('ai_studio') && (auth()->user()?->can('ai_studio.view') ?? false);
    }
}
