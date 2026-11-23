<?php

namespace App\Support\Permissions;

use Spatie\Permission\Models\Permission;

/**
 * Central list of permissions. Shown as a toggle matrix in the owner dashboard.
 * Naming: {area}.{ability}. Platform permissions are prefixed "platform.".
 */
class PermissionCatalog
{
    public const TENANT = [
        'branches' => ['view', 'manage'],
        'staff' => ['view', 'manage', 'schedules'],
        'catalog' => ['view', 'manage', 'prices'],
        'inventory' => ['view', 'manage', 'transfer', 'purchase'],
        'clients' => ['view', 'manage', 'export', 'private_notes'],
        'bookings' => ['view', 'manage', 'own_only'],
        'pos' => ['access', 'discount', 'refund', 'void', 'cash_drawer', 'close_shift'],
        'shop' => ['view', 'manage'],
        'marketing' => ['view', 'manage', 'send'],
        'ai_studio' => ['view', 'manage'],
        'website' => ['manage'],
        'reports' => ['view', 'revenue', 'export'],
        'payments' => ['manage'],
        'settings' => ['manage', 'billing', 'roles'],
    ];

    public const PLATFORM = [
        'tenants' => ['view', 'manage', 'impersonate'],
        'billing' => ['view', 'manage'],
        'plans' => ['manage'],
        'cms' => ['manage'],
        'settings' => ['manage'],
        'leads' => ['manage'],
    ];

    public static function all(): array
    {
        $out = [];
        foreach (self::TENANT as $area => $abilities) {
            foreach ($abilities as $a) {
                $out[] = "$area.$a";
            }
        }
        foreach (self::PLATFORM as $area => $abilities) {
            foreach ($abilities as $a) {
                $out[] = "platform.$area.$a";
            }
        }

        return $out;
    }

    public static function ensurePermissions(): void
    {
        $existing = Permission::query()->pluck('name')->all();
        foreach (array_diff(self::all(), $existing) as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }
    }

    public static function tenantRoleDefaults(): array
    {
        return [
            'owner' => ['*'],
            'branch_manager' => [
                'branches.view', 'staff.view', 'staff.manage', 'staff.schedules', 'catalog.view', 'inventory.view', 'inventory.manage', 'inventory.transfer',
                'clients.view', 'clients.manage', 'clients.private_notes', 'bookings.view', 'bookings.manage', 'pos.access', 'pos.discount', 'pos.refund',
                'pos.void', 'pos.cash_drawer', 'pos.close_shift', 'shop.view', 'shop.manage', 'reports.view', 'reports.revenue',
            ],
            'receptionist' => ['clients.view', 'clients.manage', 'bookings.view', 'bookings.manage', 'pos.access', 'pos.cash_drawer', 'shop.view', 'catalog.view'],
            'stylist' => ['bookings.view', 'bookings.own_only', 'clients.view', 'clients.private_notes', 'catalog.view'],
            'accountant' => ['reports.view', 'reports.revenue', 'reports.export', 'inventory.view', 'clients.view', 'shop.view', 'settings.billing'],
            'marketing' => ['clients.view', 'clients.export', 'marketing.view', 'marketing.manage', 'marketing.send', 'ai_studio.view', 'website.manage', 'reports.view'],
        ];
    }

    public static function platformRoleDefaults(): array
    {
        return [
            'platform_owner' => ['*'],
            'sales' => ['platform.tenants.view', 'platform.tenants.manage', 'platform.leads.manage', 'platform.billing.view'],
            'support' => ['platform.tenants.view', 'platform.tenants.impersonate'],
            'finance' => ['platform.tenants.view', 'platform.billing.view', 'platform.billing.manage'],
        ];
    }
}
