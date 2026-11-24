<?php

namespace App\Domain\Team\Actions;

use App\Models\User;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sets (or clears) a team member's POS PIN — used at the till for manager approvals and cashier switching.
 *
 * A PIN identifies its owner on its own, so it must be unique within the salon. To stop the uniqueness check
 * being used to guess a colleague's PIN, rejected PINs share one generic message and are throttled per user.
 */
class SetPosPin
{
    public const MIN = 4;

    public const MAX = 6;

    public function handle(User $user, ?string $pin): User
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        abort_unless($user->tenant_id !== null && $user->tenant_id === tenant()?->id, 404);

        if ($pin === null || $pin === '') {
            $user->forceFill(['pos_pin' => null])->save();
            activity()->performedOn($user)->causedBy(auth()->user())->log('team.pos_pin_cleared');

            return $user;
        }

        if (! preg_match('/^\d{'.self::MIN.','.self::MAX.'}$/', $pin)) {
            throw new DomainException(__('workspace.pin.format', ['min' => self::MIN, 'max' => self::MAX]));
        }

        $throttle = 'pos-pin-set:'.(auth()->id() ?? $user->id);
        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            throw new DomainException(__('pos.errors.pin_throttled', ['seconds' => RateLimiter::availableIn($throttle)]));
        }

        if (self::isWeak($pin) || $this->inUseByAnother($user, $pin)) {
            RateLimiter::hit($throttle, 3600);
            throw new DomainException(__('workspace.pin.rejected'));
        }

        $user->setPosPin($pin);
        activity()->performedOn($user)->causedBy(auth()->user())->log('team.pos_pin_set');

        return $user;
    }

    /** Same digit repeated (1111) or a straight run up or down (1234, 9876). */
    public static function isWeak(string $pin): bool
    {
        if (count(array_unique(str_split($pin))) === 1) {
            return true;
        }
        $up = $down = true;
        for ($i = 1; $i < strlen($pin); $i++) {
            $diff = (int) $pin[$i] - (int) $pin[$i - 1];
            $up = $up && $diff === 1;
            $down = $down && $diff === -1;
        }

        return $up || $down;
    }

    private function inUseByAnother(User $user, string $pin): bool
    {
        return User::query()
            ->where('tenant_id', $user->tenant_id)->where('is_platform', false)
            ->whereKeyNot($user->getKey())->whereNotNull('pos_pin')
            ->get()
            ->contains(fn (User $other) => $other->checkPosPin($pin));
    }
}
