<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('salon.locales'));

        if (($q = $request->query('lang')) && in_array($q, $supported, true)) {
            if ($request->hasSession()) {
                $request->session()->put('locale', $q);
            }
        }

        $locale = ($request->hasSession() ? $request->session()->get('locale') : null)
            ?? $request->user()?->locale
            ?? ($request->hasHeader('Accept-Language') && in_array(substr($request->header('Accept-Language'), 0, 2), $supported, true) && $request->is('api/*') ? substr($request->header('Accept-Language'), 0, 2) : null)
            ?? tenant()?->default_locale
            ?? config('app.locale');

        app()->setLocale(in_array($locale, $supported, true) ? $locale : 'en');

        return $next($request);
    }
}
