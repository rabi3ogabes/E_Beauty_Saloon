<?php

namespace App\Http\Controllers\Api\V1;

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
    /** Request a code. */
    public function requestCode(Request $request, SendClientOtp $send)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $otp = $send->handle($data['phone']);

        return response()->json(['message' => __('account.code_sent'), 'phone' => $otp->phone, 'expires_in' => 600], 202);
    }

    /** Verify the code and receive an API token. */
    public function verify(Request $request, VerifyClientOtp $verify)
    {
        $data = $request->validate([
            'phone' => ['required', 'string'], 'code' => ['required', 'digits:6'],
            'name' => ['nullable', 'string', 'max:100'], 'device_name' => ['required', 'string', 'max:80'],
        ]);
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
