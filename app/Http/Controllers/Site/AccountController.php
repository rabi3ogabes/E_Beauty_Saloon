<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Account\AccountSections;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __invoke(Request $request, AccountSections $sections, ?string $section = null)
    {
        $client = $request->user('client');
        $available = $sections->available();
        $current = $section ? $sections->get($section) : null;
        abort_if($section && ! $current, 404);

        return view('site.account.show', [
            'client' => $client,
            'sections' => $available,
            'currentKey' => $section,
            'content' => $current ? ($current['render'])($client, $request) : null,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $client = $request->user('client');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'birthday' => ['nullable', 'date', 'before:today'],
            'locale' => ['required', 'in:en,ar'],
            'consents' => ['array'],
        ]);
        $client->update(collect($data)->except('consents')->all());
        foreach (\App\Domain\CRM\Models\Client::CHANNELS as $channel) {
            $client->setConsent($channel, in_array($channel, $data['consents'] ?? [], true), 'account', $request->ip());
        }

        return back()->with('status', __('account.saved'));
    }
}
