<?php

namespace App\Support\Auth;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

/**
 * Password-reset / invitation e-mails. Laravel's default link points at a `password.reset` route this app does
 * not have; we send people to their own panel's reset page instead — the salon's dashboard on the salon's
 * domain, or the platform admin — and word a first-time invite as an invitation.
 */
class PasswordLinks
{
    public static function register(): void
    {
        ResetPassword::createUrlUsing(fn ($user, string $token) => static::url($user, $token));
        ResetPassword::toMailUsing(fn ($user, string $token) => static::mail($user, $token));
    }

    public static function url(User $user, string $token): string
    {
        $panel = Filament::getPanel($user->is_platform ? 'admin' : 'dashboard');
        $root = ! $user->is_platform && $user->tenant ? $user->tenant->url() : config('app.url');

        // Signed URLs cover the host, so sign against the address the person will actually open.
        URL::forceRootUrl(rtrim($root, '/'));
        try {
            return $panel->getResetPasswordUrl($token, $user);
        } finally {
            URL::forceRootUrl(null);
        }
    }

    public static function mail(User $user, string $token): MailMessage
    {
        $url = static::url($user, $token);
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
        $invite = ! $user->is_platform && ! $user->last_login_at;
        $salon = $user->tenant?->name ?? config('salon.name');

        if ($invite) {
            return (new MailMessage)
                ->subject(__('workspace.team.invite_mail.subject', ['salon' => $salon]))
                ->greeting(__('workspace.team.invite_mail.greeting', ['name' => $user->name]))
                ->line(__('workspace.team.invite_mail.body', ['salon' => $salon]))
                ->action(__('workspace.team.invite_mail.action'), $url)
                ->line(__('workspace.team.invite_mail.expiry', ['minutes' => $minutes]));
        }

        return (new MailMessage)
            ->subject(__('workspace.team.reset_mail.subject'))
            ->line(__('workspace.team.reset_mail.body'))
            ->action(__('workspace.team.reset_mail.action'), $url)
            ->line(__('workspace.team.invite_mail.expiry', ['minutes' => $minutes]))
            ->line(__('workspace.team.reset_mail.ignore'));
    }
}
