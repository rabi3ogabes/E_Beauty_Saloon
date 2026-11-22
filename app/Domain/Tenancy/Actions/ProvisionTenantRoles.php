<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Models\Tenant;
use App\Support\Permissions\PermissionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Creates the tenant's role set (spatie team roles, team = tenant) with sensible default permissions. */
class ProvisionTenantRoles
{
    public function handle(Tenant $tenant): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($tenant->id);

        PermissionCatalog::ensurePermissions();

        foreach (PermissionCatalog::tenantRoleDefaults() as $role => $permissions) {
            $r = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
            $r->syncPermissions($permissions === ['*'] ? Permission::where('name', 'not like', 'platform.%')->pluck('name') : $permissions);
        }

        $registrar->setPermissionsTeamId($previous);
        $registrar->forgetCachedPermissions();
    }
}
