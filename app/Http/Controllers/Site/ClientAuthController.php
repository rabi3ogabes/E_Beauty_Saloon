<?php

namespace App\Http\Controllers\Site;

use App\Domain\CRM\Actions\SendClientOtp;
use App\Domain\CRM\Actions\VerifyClientOtp;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Phone OTP sign-in for salon clients (website). */
class ClientAuthController extends Controller
{
    public function show(Request $request)
    {
        if (Auth::guard('client')->check()) {
            return redirect()->intended('/account');
        }

        return view('site.account.login', ['phone' => $request->old('phone', session('otp_phone')), 'step' => session('otp_phone') ? 'code' : 'phone']);
    }

    public function send(Request $request, SendClientOtp $send)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        try {
            $otp = $send->handle($data['phone']);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['phone' => $e->getMessage()]);
        }

        return back()->with('otp_phone', $otp->phone)->with('status', __('account.code_sent'));
    }

    public function verify(Request $request, VerifyClientOtp $verify)
    {
        $data = $request->validate(['phone' => ['required', 'string'], 'code' => ['required', 'digits:6'], 'name' => ['nullable', 'string', 'max:100']]);
        try {
            $client = $verify->handle($data['phone'], $data['code'], $data['name'] ?? null);
        } catch (DomainException $e) {
            return back()->with('otp_phone', $data['phone'])->withErrors(['code' => $e->getMessage()]);
        }

        Auth::guard('client')->login($client, remember: true);
        $request->session()->regenerate();

        return redirect()->intended('/account');
    }

    public function logout(Request $request)
    {
        Auth::guard('client')->logout();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
