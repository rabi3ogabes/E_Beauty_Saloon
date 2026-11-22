<?php

namespace App\Domain\Team\Actions;

use App\Domain\Branches\Models\Branch;
use App\Domain\Team\Models\Staff;
use App\Models\User;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Team logins: creates a dashboard user for the current salon, assigns a tenant role and branches,
 * optionally links a staff profile, and emails a "set your password" link via the password broker.
 */
class InviteTeamMember
{
    public const ROLES = ['branch_manager', 'receptionist', 'stylist', 'accountant', 'marketing'];

    public function handle(string $name, string $email, string $role, array $branchIds = [], ?int $staffId = null, bool $sendInvite = true): User
    {
        $tenant = tenant() ?? throw new \RuntimeException('No tenant');
        abort_if($tenant->isReadOnly(), 423, __('salon.errors.read_only'));

        if (! in_array($role, self::ROLES, true)) {
            throw new DomainException(__('workspace.team.invalid_role'));
        }
        $email = Str::lower(trim($email));
        if (User::where('email', $email)->exists()) {
            throw new DomainException(__('salon.errors.email_taken'));
        }

        $user = DB::transaction(function () use ($tenant, $name, $email, $role, $branchIds, $staffId) {
            $user = new User(['name' => $name, 'email' => $email, 'password' => Str::random(40)]);
            $user->tenant_id = $tenant->id;
            $user->save();
            $user->assignRole($role);
            $user->branches()->sync($this->validBranchIds($branchIds));

            if ($staffId && ($staff = Staff::find($staffId))) {
                $staff->update(['user_id' => $user->id]);
            }

            return $user;
        });

        if ($sendInvite) {
            Password::broker()->sendResetLink(['email' => $email]);
        }

        activity()->performedOn($user)->causedBy(auth()->user())->withProperties(['role' => $role, 'branches' => $branchIds])->log('team.invited');

        return $user;
    }

    protected function validBranchIds(array $ids): array
    {
        return Branch::query()->withoutGlobalScope('allowed_branches')
            ->whereIn('id', array_map('intval', $ids))->pluck('id')->all();
    }
}
