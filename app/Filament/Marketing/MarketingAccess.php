<?php

namespace App\Filament\Marketing;

/**
 * Module + permission gate shared by the Marketing/Loyalty dashboard resources and pages.
 * Disabled module ⇒ hidden from navigation AND 403.
 */
final class MarketingAccess
{
    /** @param list<string> $modules any of these modules unlocks the screen */
    public static function can(array $modules = ['marketing'], string $ability = 'marketing.view'): bool
    {
        $tenant = tenant();
        if (! $tenant || ! collect($modules)->contains(fn ($m) => $tenant->hasModule($m))) {
            return false;
        }

        return (bool) auth()->user()?->can($ability);
    }

    public static function view(array $modules = ['marketing']): bool
    {
        return self::can($modules, 'marketing.view');
    }

    public static function manage(array $modules = ['marketing']): bool
    {
        return self::can($modules, 'marketing.manage') && ! tenant()?->isReadOnly();
    }

    public static function send(): bool
    {
        return self::can(['marketing'], 'marketing.send') && ! tenant()?->isReadOnly();
    }
}
