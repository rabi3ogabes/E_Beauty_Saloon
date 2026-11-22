<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Platform\PlatformPageController;
use App\Http\Controllers\Site\SitePageController;

/** "/" serves the salon website on tenant domains and the platform website on central domains. */
class HomeController extends Controller
{
    public function __invoke()
    {
        if (tenant()) {
            if (! tenant()->status->canAccessDashboard()) {
                return response()->view('errors.tenant-unavailable', ['tenant' => tenant()], 503);
            }

            return app()->call([app(SitePageController::class), 'home']);
        }

        return app()->call([app(PlatformPageController::class), 'home']);
    }
}
