<?php

namespace App\Domain\POS\Actions;

use App\Domain\POS\Models\Register;
use App\Domain\POS\Models\Shift;
use App\Models\User;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/** Opens the drawer of a register with a counted float. One open shift per register. */
class OpenShift
{
    public function handle(Register $register, User $user, int $openingFloat): Shift
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        if (! $user->can('pos.cash_drawer')) {
            throw new DomainException(__('pos.errors.forbidden'));
        }

        return DB::transaction(function () use ($register, $user, $openingFloat) {
            Register::withoutGlobalScopes()->lockForUpdate()->findOrFail($register->id);
            if (Shift::withoutGlobalScopes()->where('pos_register_id', $register->id)->where('status', 'open')->exists()) {
                throw new DomainException(__('pos.errors.shift_already_open'));
            }

            $shift = Shift::create([
                'branch_id' => $register->branch_id,
                'pos_register_id' => $register->id,
                'user_id' => $user->id,
                'status' => 'open',
                'opening_float' => max(0, $openingFloat),
                'opened_at' => now(),
            ]);
            activity()->performedOn($shift)->causedBy($user)->withProperties(['float' => $openingFloat, 'register' => $register->name])->log('pos.shift.opened');

            return $shift;
        });
    }
}
