<?php

namespace App\Domain\Team\Actions;

use App\Domain\Branches\Models\Branch;
use App\Models\User;
use App\Support\Exceptions\DomainException;

/** Changes a team member's role, branches and active flag. The owner account cannot be changed here. */
class UpdateTeamMember
{
    public function handle(User $user, string $role, array $branchIds, bool $active = true): User
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        abort_unless($user->tenant_id === tenant()?->id, 404);

        if ($user->isOwner()) {
            throw new DomainException(__('workspace.team.owner_locked'));
        }
        if (! in_array($role, InviteTeamMember::ROLES, true)) {
            throw new DomainException(__('workspace.team.invalid_role'));
        }

        $user->syncRoles([$role]);
        $user->branches()->sync(Branch::query()->withoutGlobalScope('allowed_branches')->whereIn('id', $branchIds)->pluck('id')->all());
        $user->forceFill(['is_active' => $active])->save();

        activity()->performedOn($user)->causedBy(auth()->user())->withProperties(['role' => $role, 'branches' => $branchIds, 'active' => $active])->log('team.updated');

        return $user->refresh();
    }
}
