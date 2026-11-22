<?php

namespace App\Domain\Marketing\Support;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\URL;

/** Absolute URLs on the salon's own domain — safe in queued jobs where there is no request host. */
final class MarketingUrl
{
    public static function to(string $path, ?Tenant $tenant = null): string
    {
        $tenant ??= tenant();
        if (! $tenant) {
            return url($path);
        }
        if (request()->getHost() && $tenant->domains()->where('domain', request()->getHost())->exists()) {
            return url($path);
        }

        return $tenant->url($path);
    }

    public static function trackedLink(string $token): string
    {
        return self::to('/c/'.$token);
    }

    public static function unsubscribe(string $token): string
    {
        return self::to('/u/'.$token);
    }

    /** Signed absolute URL for a named tenant route on the salon domain. */
    public static function signedRoute(string $name, array $params = []): string
    {
        $relative = URL::signedRoute($name, $params, absolute: false);

        return self::to($relative);
    }
}
