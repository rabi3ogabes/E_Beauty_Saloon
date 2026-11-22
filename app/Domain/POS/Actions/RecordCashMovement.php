<?php

namespace App\Domain\POS\Actions;

use App\Domain\POS\Models\CashMovement;
use App\Domain\POS\Models\Shift;
use App\Models\User;
use App\Support\Exceptions\DomainException;

/** Paid-in / paid-out of the drawer (petty cash, bank drop, change top-up) with a reason. */
class RecordCashMovement
{
    public function handle(Shift $shift, User $user, string $type, int $amount, string $reason): CashMovement
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        if (! $user->can('pos.cash_drawer')) {
            throw new DomainException(__('pos.errors.forbidden'));
        }
        if (! $shift->isOpen()) {
            throw new DomainException(__('pos.errors.shift_closed'));
        }
        if (! in_array($type, ['in', 'out'], true) || $amount <= 0 || trim($reason) === '') {
            throw new DomainException(__('pos.errors.cash_movement_invalid'));
        }

        $movement = $shift->cashMovements()->create(['tenant_id' => $shift->tenant_id, 'user_id' => $user->id, 'type' => $type, 'amount' => $amount, 'reason' => trim($reason)]);
        activity()->performedOn($shift)->causedBy($user)->withProperties(['type' => $type, 'amount' => $amount, 'reason' => $reason])->log('pos.cash.'.$type);

        return $movement;
    }
}
