<?php

namespace App\Filament\Marketing;

use Illuminate\Database\Eloquent\Model;

/**
 * Use in Marketing/Loyalty resources. Override static $modules (default ['marketing']).
 */
trait GatedResource
{
    public static function gateModules(): array
    {
        return property_exists(static::class, 'modules') ? static::$modules : ['marketing'];
    }

    public static function canViewAny(): bool
    {
        return MarketingAccess::view(static::gateModules());
    }

    public static function canCreate(): bool
    {
        return MarketingAccess::manage(static::gateModules());
    }

    public static function canEdit(Model $record): bool
    {
        return MarketingAccess::manage(static::gateModules());
    }

    public static function canDelete(Model $record): bool
    {
        return MarketingAccess::manage(static::gateModules());
    }

    public static function canDeleteAny(): bool
    {
        return MarketingAccess::manage(static::gateModules());
    }

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.marketing');
    }
}
