<?php

namespace App\Domain\Team\Actions;

use App\Support\Exceptions\DomainException;
use App\Support\Permissions\PermissionCatalog;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles & permissions matrix toggle: grant/revoke one tenant permission on one of the
 * current salon's roles (spatie team = tenant). The owner role always keeps everything.
 */
class SetRolePermission
{
    public function handle(string $roleName, string $permission, bool $granted): Role
    {
        $tenant = tenant() ?? throw new \RuntimeException('No tenant');
        abort_if($tenant->isReadOnly(), 423, __('salon.errors.read_only'));

        if ($roleName === 'owner') {
            throw new DomainException(__('workspace.roles.owner_locked'));
        }
        if (! in_array($permission, static::tenantPermissions(), true)) {
            throw new DomainException(__('workspace.roles.unknown_permission'));
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $role = Role::where('name', $roleName)->where('tenant_id', $tenant->id)->where('guard_name', 'web')->firstOrFail();

        PermissionCatalog::ensurePermissions();
        $granted ? $role->givePermissionTo($permission) : $role->revokePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        activity()->performedOn($tenant)->causedBy(auth()->user())
            ->withProperties(['role' => $roleName, 'permission' => $permission, 'granted' => $granted])->log('roles.permission_changed');

        return $role;
    }

    /** @return list<string> */
    public static function tenantPermissions(): array
    {
        return collect(PermissionCatalog::TENANT)->flatMap(fn ($abilities, $area) => collect($abilities)->map(fn ($a) => "$area.$a"))->values()->all();
    }
}
