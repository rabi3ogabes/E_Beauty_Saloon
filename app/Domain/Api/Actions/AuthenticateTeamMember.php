<?php

namespace App\Domain\Api\Actions;

use App\Domain\Api\Exceptions\TeamLoginFailed;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

/**
 * Staff / owner app sign-in: e-mail + password of a team member of the current salon, plus the
 * authenticator code when the member enabled app MFA in the dashboard (Filament app authentication,
 * same secret and recovery codes). Returns the user; the caller issues a token with the app ability.
 *
 *  - staff app: needs a stylist profile or bookings.view
 *  - owner app: owner, or reports.view (e.g. branch managers)
 */
class AuthenticateTeamMember
{
    public const APPS = ['staff', 'owner'];

    public function handle(string $email, string $password, ?string $code, string $app): User
    {
        $user = User::query()->where('tenant_id', tenant()->id)->where('is_platform', false)
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();

        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            throw new TeamLoginFailed(__('api.auth.invalid_credentials'), 'invalid_credentials', 422);
        }
        if ($user->is_active === false) {
            throw new TeamLoginFailed(__('api.auth.inactive'), 'account_disabled', 403);
        }
        if (! $this->allowed($user, $app)) {
            throw new TeamLoginFailed(__('api.auth.not_allowed'), 'app_not_allowed', 403);
        }

        if ($secret = $user->getAppAuthenticationSecret()) {
            $code = preg_replace('/\s+/', '', (string) $code);
            if ($code === '') {
                throw new TeamLoginFailed(__('api.auth.mfa_required'), 'mfa_required', 422, ['mfa_required' => true]);
            }
            if (! $this->verifyCode($user, $secret, $code)) {
                throw new TeamLoginFailed(__('api.auth.mfa_invalid'), 'mfa_invalid', 422, ['mfa_required' => true]);
            }
        }

        $user->forceFill(['last_login_at' => now()])->save();
        activity()->performedOn($user)->causedBy($user)->withProperties(['app' => $app])->log('api.team_login');

        return $user;
    }

    public function allowed(User $user, string $app): bool
    {
        return match ($app) {
            'staff' => $user->staffProfile()->exists() || $user->can('bookings.view'),
            'owner' => $user->isOwner() || $user->can('reports.view'),
            default => false,
        };
    }

    /** TOTP (±4 steps, like the dashboard) or a one-time recovery code, which is consumed. */
    protected function verifyCode(User $user, string $secret, string $code): bool
    {
        if (ctype_digit($code) && strlen($code) === 6) {
            return (bool) app(Google2FA::class)->verifyKey($secret, $code, 4);
        }

        $codes = $user->getAppAuthenticationRecoveryCodes() ?? [];
        foreach ($codes as $i => $hashed) {
            $hashed = (string) $hashed;
            $matches = str_starts_with($hashed, '$') ? Hash::check($code, $hashed) : hash_equals($hashed, $code);
            if ($matches) {
                unset($codes[$i]);
                $user->saveAppAuthenticationRecoveryCodes(array_values($codes));

                return true;
            }
        }

        return false;
    }
}
