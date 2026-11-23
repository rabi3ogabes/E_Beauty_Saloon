<x-layouts.site :title="__('account.title')">
    <section class="container-luxe py-12 sm:py-16">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow">{{ __('account.title') }}</p>
                <h1 class="mt-2 text-3xl font-semibold sm:text-4xl">{{ __('account.hello', ['name' => $client->firstName()]) }}</h1>
            </div>
            <form method="POST" action="{{ route('client.logout') }}">@csrf<button class="btn-ghost">{{ __('account.sign_out') }}</button></form>
        </div>

        <div class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="card p-5"><p class="text-xs text-muted">{{ __('account.wallet') }}</p><p class="mt-1 font-display text-2xl">{{ money($client->wallet_balance) }}</p></div>
            <div class="card p-5"><p class="text-xs text-muted">{{ __('account.points') }}</p><p class="mt-1 font-display text-2xl">{{ number_format($client->loyalty_points) }}</p>
                @if ($client->loyalty_tier)<p class="text-xs capitalize text-gold-dark">{{ $client->loyalty_tier }}</p>@endif</div>
            <div class="card p-5"><p class="text-xs text-muted">{{ __('account.visits') }}</p><p class="mt-1 font-display text-2xl">{{ $client->visits_count }}</p></div>
            <div class="card p-5"><p class="text-xs text-muted">{{ __('account.referral') }}</p><p class="mt-1 font-mono text-lg tracking-wider">{{ $client->referral_code }}</p></div>
        </div>

        <div class="mt-10 grid gap-8 lg:grid-cols-[240px_1fr]">
            <nav class="flex gap-2 overflow-x-auto lg:flex-col" aria-label="{{ __('account.title') }}">
                <a href="{{ route('client.account') }}" @class(['chip whitespace-nowrap', 'chip-active' => ! $currentKey])>{{ __('account.profile') }}</a>
                @foreach ($sections as $key => $section)
                    <a href="{{ route('client.account', $key) }}" @class(['chip whitespace-nowrap', 'chip-active' => $currentKey === $key])>{{ ($section['label'])() }}</a>
                @endforeach
            </nav>

            <div>
                @if ($content)
                    {!! $content !!}
                @else
                    <form method="POST" action="{{ route('client.account.profile') }}" class="card space-y-5 p-6 sm:p-8">
                        @csrf
                        @if (session('status'))<p class="rounded-xl bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</p>@endif
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div><label class="label" for="name">{{ __('account.your_name') }}</label><input id="name" name="name" class="input" value="{{ old('name', $client->name) }}" required></div>
                            <div><label class="label">{{ __('account.phone') }}</label><input class="input bg-ivory" value="{{ $client->phone }}" dir="ltr" disabled></div>
                            <div><label class="label" for="email">{{ __('fields.email') }}</label><input id="email" name="email" type="email" class="input" value="{{ old('email', $client->email) }}"></div>
                            <div><label class="label" for="birthday">{{ __('account.birthday') }}</label><input id="birthday" name="birthday" type="date" class="input" value="{{ old('birthday', $client->birthday?->toDateString()) }}"></div>
                            <div><label class="label" for="locale">{{ __('salon.ui.language') }}</label>
                                <select id="locale" name="locale" class="input">@foreach (config('salon.locales') as $code => $label)<option value="{{ $code }}" @selected($client->locale === $code)>{{ $label }}</option>@endforeach</select></div>
                        </div>
                        <fieldset>
                            <legend class="label">{{ __('account.consents') }}</legend>
                            <div class="mt-2 flex flex-wrap gap-3">
                                @foreach (\App\Domain\CRM\Models\Client::CHANNELS as $channel)
                                    <label class="chip cursor-pointer"><input type="checkbox" name="consents[]" value="{{ $channel }}" class="accent-ink" @checked($client->hasConsent($channel))> {{ __('account.channels.'.$channel) }}</label>
                                @endforeach
                            </div>
                        </fieldset>
                        @if ($errors->any())<p class="text-sm text-danger">{{ $errors->first() }}</p>@endif
                        <button class="btn-primary">{{ __('salon.ui.save') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </section>
</x-layouts.site>
