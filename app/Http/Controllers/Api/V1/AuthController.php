<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Support\ReviewLogin;
use App\Domain\CRM\Actions\SendClientOtp;
use App\Domain\CRM\Actions\VerifyClientOtp;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ClientResource;
use Illuminate\Http\Request;

/**
 * @group Authentication
 *
 * Clients sign in with a one-time code sent by WhatsApp/SMS and receive a Sanctum token.
 * Send `X-Tenant: {salon-slug}` (or call the salon's own domain).
 */
class AuthController extends Controller
{
    /**
     * Request a code.
     *
     * The App Review phone (salon settings `api.review_phone` / `api.review_code`) gets no message: its fixed code works.
     *
     * @unauthenticated
     *
     * @bodyParam phone string required Local or E.164. Example: 55551234
     */
    public function requestCode(Request $request, SendClientOtp $send)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $otp = ReviewLogin::matches(tenant(), $data['phone'])
            ? ReviewLogin::prepare(tenant(), $data['phone'])
            : $send->handle($data['phone']);

        return response()->json(['message' => __('account.code_sent'), 'phone' => $otp->phone, 'expires_in' => 600], 202);
    }

    /**
     * Verify the code and receive an API token.
     *
     * @unauthenticated
     *
     * @bodyParam phone string required Example: +97455551234
     * @bodyParam code string required Example: 123456
     * @bodyParam device_name string required Example: Reem's iPhone
     * @bodyParam name string Name for a first sign-in. Example: Reem
     */
    public function verify(Request $request, VerifyClientOtp $verify)
    {
        $data = $request->validate([
            'phone' => ['required', 'string'], 'code' => ['required', 'digits:6'],
            'name' => ['nullable', 'string', 'max:100'], 'device_name' => ['required', 'string', 'max:80'],
        ]);
        if (ReviewLogin::codeMatches(tenant(), $data['phone'], $data['code'])) {
            ReviewLogin::prepare(tenant(), $data['phone']); // works even if the reviewer skipped "send code"
        }
        $client = $verify->handle($data['phone'], $data['code'], $data['name'] ?? null, 'app');
        $token = $client->createToken($data['device_name'], ['client'])->plainTextToken;

        return response()->json(['token' => $token, 'token_type' => 'Bearer', 'client' => new ClientResource($client)]);
    }

    /** Revoke the current token. */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
