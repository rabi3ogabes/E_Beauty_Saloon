<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Actions\AuthenticateTeamMember;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TeamMemberResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Staff & owner authentication
 *
 * Team members sign in with their dashboard e-mail and password. When app MFA (authenticator) is enabled on the
 * account, the first attempt answers 422 with `error.code = mfa_required`; repeat with `code`. Tokens carry the
 * ability of the app (`staff` or `owner`) and only work on that app's endpoints, for this salon.
 */
class TeamAuthController extends Controller
{
    /**
     * Staff app sign-in.
     *
     * @unauthenticated
     *
     * @bodyParam email string required Example: layla@lumiere.test
     * @bodyParam password string required Example: password
     * @bodyParam code string Authenticator code (or a recovery code) when MFA is enabled. Example: 123456
     * @bodyParam device_name string required Example: Layla's iPhone
     */
    public function staffLogin(Request $request, AuthenticateTeamMember $auth): JsonResponse
    {
        return $this->login($request, $auth, 'staff');
    }

    /**
     * Owner app sign-in.
     *
     * @unauthenticated
     *
     * @bodyParam email string required Example: owner@lumiere.test
     * @bodyParam password string required Example: password
     * @bodyParam code string Authenticator code when MFA is enabled.
     * @bodyParam device_name string required Example: Owner iPad
     */
    public function ownerLogin(Request $request, AuthenticateTeamMember $auth): JsonResponse
    {
        return $this->login($request, $auth, 'owner');
    }

    /** Revoke the current staff / owner token. */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    protected function login(Request $request, AuthenticateTeamMember $auth, string $app): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:190'],
            'code' => ['nullable', 'string', 'max:32'],
            'device_name' => ['required', 'string', 'max:80'],
        ]);
        $user = $auth->handle($data['email'], $data['password'], $data['code'] ?? null, $app);
        $token = $user->createToken($app.': '.$data['device_name'], [$app], now()->addDays(90))->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'abilities' => [$app],
            'expires_at' => now()->addDays(90)->toIso8601ZuluString(),
            'user' => new TeamMemberResource($user),
        ]);
    }
}
