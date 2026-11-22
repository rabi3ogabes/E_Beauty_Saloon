<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Models\ImpersonationLog;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Str;

/**
 * Super admin "Login as owner": creates a one-time token (60s) and returns the URL on the
 * tenant's domain that consumes it. Every impersonation is logged with reason + times.
 */
class StartImpersonation
{
    public function handle(User $admin, Tenant $tenant, string $reason, ?User $target = null): string
    {
        if (! $admin->is_platform) {
            throw new DomainException('Only platform staff can impersonate.');
        }
        $target ??= $tenant->owner;
        if (! $target || $target->tenant_id !== $tenant->id) {
            throw new DomainException(__('salon.errors.no_owner'));
        }

        $log = ImpersonationLog::create([
            'admin_user_id' => $admin->id,
            'tenant_id' => $tenant->id,
            'impersonated_user_id' => $target->id,
            'reason' => $reason,
            'token' => Str::random(64),
            'ip' => request()->ip(),
        ]);

        activity()->causedBy($admin)->performedOn($tenant)->withProperties(['reason' => $reason, 'log_id' => $log->id])->log('impersonation.started');

        return $tenant->url('impersonate/'.$log->token);
    }
}
