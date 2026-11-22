<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After authentication: restrict branch managers / stylists / cashiers to their branches and
 * apply the top-bar branch switcher selection (session "branch_id").
 */
class ApplyBranchScope
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && tenant() && $user->tenant_id === tenant()->id) {
            $allowed = $user->restrictedBranchIds();
            $this->context->restrictToBranches($allowed);

            $selected = $request->hasSession() ? $request->session()->get('branch_id') : null;
            if ($selected && ($allowed === null || in_array((int) $selected, $allowed, true))) {
                $this->context->setBranch((int) $selected);
            } elseif ($allowed !== null && count($allowed) === 1) {
                $this->context->setBranch($allowed[0]);
            }
        }

        return $next($request);
    }
}
