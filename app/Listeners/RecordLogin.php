<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\NewLoginAlert;
use Illuminate\Auth\Events\Login;

/** Tracks last login and alerts the user when a sign-in comes from a new IP address. */
class RecordLogin
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }
        $user = $event->user;
        $ip = request()->ip();
        $isNew = $user->last_login_ip !== null && $user->last_login_ip !== $ip && ! session()->has('impersonation');

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->saveQuietly();
        activity()->causedBy($user)->withProperties(['ip' => $ip, 'agent' => substr((string) request()->userAgent(), 0, 180)])->log('auth.login');

        if ($isNew) {
            $user->notify(new NewLoginAlert($ip, (string) request()->userAgent()));
        }
    }
}
