<?php

namespace App\Filament\Dashboard\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Relation managers become read-only during the grace period or without the manage permission.
 * The using class declares: protected static string $managePermission.
 */
trait WorkspaceRelationManager
{
    public function isReadOnly(): bool
    {
        return (tenant()?->isReadOnly() ?? true) || ! auth()->user()?->can(static::$managePermission);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
