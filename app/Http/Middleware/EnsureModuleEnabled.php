<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('module:booking'). Blocks routes/API of modules not in the subscription. */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $tenant = tenant();

        if (! $tenant || ! Feature::for($tenant)->active('module:'.$module)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('salon.errors.module_disabled'), 'module' => $module], 403);
            }
            abort(403, __('salon.errors.module_disabled'));
        }

        return $next($request);
    }
}
