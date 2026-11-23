<?php

namespace App\Domain\Api\Http\Middleware;

use App\Domain\CRM\Models\Client;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage (after auth:sanctum): ->middleware('api.actor:client') | 'api.actor:staff' | 'api.actor:owner'.
 *
 * A Sanctum token is only valid for the salon that issued it, for the app it was issued to (token ability
 * client / staff / owner) and for the right kind of account (Client vs team User). For team users the
 * per-user branch restrictions are applied exactly like the dashboard does (ApplyBranchScope).
 */
class EnsureApiActor
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next, string ...$kinds): Response
    {
        $user = $request->user();
        if (! $user || (int) ($user->tenant_id ?? 0) !== (int) tenant()?->id) {
            return response()->json(['message' => __('api.errors.unauthenticated')], 401);
        }

        $kind = collect($kinds)->first(fn (string $k) => $this->matches($user, $k));
        if (! $kind) {
            return response()->json(['message' => __('api.errors.wrong_app')], 403);
        }

        if ($user instanceof User) {
            Auth::shouldUse('sanctum'); // auth()->user() / activity causer = the team member
            $this->context->restrictToBranches($user->restrictedBranchIds());
        }
        $request->attributes->set('api_actor', $kind);

        return $next($request);
    }

    protected function matches(mixed $user, string $kind): bool
    {
        return match ($kind) {
            'client' => $user instanceof Client && ! $user->is_blocked && $user->tokenCan('client'),
            'staff', 'owner' => $user instanceof User && ! $user->is_platform && $user->is_active !== false && $this->tokenHas($user, $kind),
            default => false,
        };
    }

    /** Team tokens must carry the exact ability (a wildcard dashboard token is not an app token). */
    protected function tokenHas(User $user, string $ability): bool
    {
        $token = $user->currentAccessToken();

        return $token && in_array($ability, (array) ($token->abilities ?? []), true);
    }
}
