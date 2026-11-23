<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale)
    {
        abort_unless(array_key_exists($locale, config('salon.locales')), 404);
        $request->session()->put('locale', $locale);
        $request->user()?->update(['locale' => $locale]);

        return back();
    }
}
