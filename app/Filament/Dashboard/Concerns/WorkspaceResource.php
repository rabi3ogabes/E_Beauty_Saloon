<?php

namespace App\Filament\Dashboard\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Permission + grace-period handling shared by every owner workspace resource.
 *
 * The using class declares:
 *   protected static string $viewPermission   e.g. 'catalog.view'
 *   protected static string $managePermission e.g. 'catalog.manage'
 *   protected static string $navGroup         e.g. 'catalog'  (-> __('salon.nav.catalog'))
 *
 * Owners pass every gate (Gate::before). When the subscription is in its grace period
 * (tenant()->isReadOnly()) every create/edit/delete action is hidden.
 */
trait WorkspaceResource
{
    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.'.static::$navGroup);
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(static::$viewPermission);
    }

    public static function canManage(): bool
    {
        return ! (tenant()?->isReadOnly() ?? true) && (bool) auth()->user()?->can(static::$managePermission);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManage();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManage();
    }

    public static function canDeleteAny(): bool
    {
        return static::canManage();
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return static::canManage();
    }

    public static function canRestoreAny(): bool
    {
        return static::canManage();
    }

    public static function canReplicate(Model $record): bool
    {
        return static::canManage();
    }

    public static function canReorder(): bool
    {
        return static::canManage();
    }
}
