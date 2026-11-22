<?php

namespace App\Filament\Dashboard\Pages;

use App\Domain\Team\Actions\SetRolePermission;
use App\Models\User;
use App\Support\Exceptions\DomainException;
use App\Support\Permissions\PermissionCatalog;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Roles & permissions matrix: tenant roles x PermissionCatalog::TENANT with toggles. */
class RolesPermissions extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'roles';

    protected string $view = 'filament.dashboard.pages.roles-permissions';

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.team');
    }

    public static function getNavigationLabel(): string
    {
        return __('workspace.roles.title');
    }

    public function getTitle(): string
    {
        return __('workspace.roles.title');
    }

    public function getSubheading(): ?string
    {
        return __('workspace.roles.subheading');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('settings.roles');
    }

    public function canEdit(): bool
    {
        return static::canAccess() && ! (tenant()?->isReadOnly() ?? true);
    }

    /** @return array<string, list<string>> role => granted permission names */
    public function getMatrix(): array
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(tenant()->id);

        return Role::query()->where('tenant_id', tenant()->id)->where('guard_name', 'web')->with('permissions')->get()
            ->sortBy(fn ($r) => array_search($r->name, User::TENANT_ROLES, true))
            ->mapWithKeys(fn ($r) => [$r->name => $r->permissions->pluck('name')->all()])->all();
    }

    public function getAreas(): array
    {
        return PermissionCatalog::TENANT;
    }

    public function toggle(string $role, string $permission): void
    {
        abort_unless($this->canEdit(), 403);
        $granted = ! in_array($permission, $this->getMatrix()[$role] ?? [], true);

        try {
            app(SetRolePermission::class)->handle($role, $permission, $granted);
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__($granted ? 'workspace.roles.granted' : 'workspace.roles.revoked', [
            'permission' => __('workspace.permissions.'.str_replace('.', '_', $permission)), 'role' => __('workspace.roles.names.'.$role),
        ]))->send();
    }
}
