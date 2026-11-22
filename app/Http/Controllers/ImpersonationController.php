<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Models\ImpersonationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    /** Consumes the one-time token on the tenant domain and signs in as the owner. */
    public function consume(Request $request, string $token)
    {
        $log = ImpersonationLog::where('token', $token)->whereNull('started_at')->first();

        abort_unless($log && $log->tenant_id === tenant()?->id && $log->created_at->gt(now()->subSeconds(config('salon.impersonation.token_ttl_seconds'))), 403, __('salon.impersonation.expired'));

        $log->update(['started_at' => now(), 'token' => null]);

        Auth::guard('web')->loginUsingId($log->impersonated_user_id);
        $request->session()->regenerate();
        $request->session()->put('impersonation', ['log_id' => $log->id, 'admin_id' => $log->admin_user_id, 'admin_name' => $log->admin?->name]);

        return redirect('/dashboard');
    }

    public function exit(Request $request)
    {
        $data = $request->session()->get('impersonation');
        if ($data) {
            ImpersonationLog::whereKey($data['log_id'])->whereNull('ended_at')->update(['ended_at' => now()]);
            activity()->withProperties(['log_id' => $data['log_id']])->log('impersonation.ended');
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $central = config('salon.central_domains')[0] ?? 'localhost';
        $port = in_array($request->getPort(), [80, 443]) ? '' : ':'.$request->getPort();

        return redirect()->away($request->getScheme().'://'.$central.$port.'/admin/tenants');
    }
}
