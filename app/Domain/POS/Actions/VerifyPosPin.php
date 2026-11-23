<?php

namespace App\Domain\POS\Actions;

use App\Models\User;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Finds the active team member of the current salon whose hashed POS PIN matches.
 * Used for manager approvals (discount / refund / void) and fast cashier switching.
 * Throttled: 5 wrong PINs per minute per terminal user.
 */
class VerifyPosPin
{
    public function handle(string $pin, ?string $permission = null, bool $forSwitching = false): User
    {
        $throttle = 'pos-pin:'.tenant()?->id.':'.(auth()->id() ?? request()->ip());
        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            throw new DomainException(__('pos.errors.pin_throttled', ['seconds' => RateLimiter::availableIn($throttle)]));
        }

        $pin = preg_replace('/\D/', '', $pin);
        $matches = strlen($pin) < 4 ? collect() : User::query()
            ->where('tenant_id', tenant()?->id)->where('is_platform', false)->whereNotNull('pos_pin')
            ->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true))
            ->orderBy('id')->get()
            ->filter(fn (User $u) => $u->checkPosPin($pin));
        // PINs are unique per salon (SetPosPin); older data may still share one, so prefer a holder who can approve.
        $match = $matches->first(fn (User $u) => ! $permission || $u->can($permission)) ?? $matches->first();

        if (! $match) {
            RateLimiter::hit($throttle, 60);
            throw new DomainException(__('pos.errors.pin_invalid'));
        }
        if ($permission && ! $match->can($permission)) {
            throw new DomainException(__('pos.errors.pin_no_permission'));
        }
        if ($forSwitching && ! $match->hasAnyRole(['owner', 'branch_manager', 'receptionist']) && ! $match->isOwner()) {
            throw new DomainException(__('pos.errors.pin_no_permission'));
        }
        RateLimiter::clear($throttle);

        return $match;
    }
}
