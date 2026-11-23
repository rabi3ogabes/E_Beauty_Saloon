<x-layouts.site :title="__('booking.waitlist.title')">
    <section class="container-luxe flex justify-center py-16 sm:py-24">
        <div class="card w-full max-w-md p-8 text-center sm:p-10">
            <p class="eyebrow">{{ __('booking.waitlist.eyebrow') }}</p>
            @if ($available)
                @php($local = $entry->offered_starts_at->copy()->setTimezone($tz))
                <h1 class="mt-3 text-3xl font-semibold">{{ __('booking.waitlist.heading', ['name' => $entry->client?->firstName()]) }}</h1>
                <p class="mt-4 font-display text-xl">{{ $local->translatedFormat('l j F') }} · <span dir="ltr">{{ $local->format('H:i') }}</span></p>
                <p class="mt-1 text-sm text-muted">{{ $services->pluck('name')->implode(' + ') }} · {{ $entry->branch->name }}</p>
                <p class="mt-4 text-xs text-gold-dark">{{ __('booking.waitlist.expires', ['time' => $entry->offer_expires_at->copy()->setTimezone($tz)->format('H:i')]) }}</p>
                @if ($errors->any())<p class="mt-4 text-sm text-danger" role="alert">{{ $errors->first() }}</p>@endif
                <form method="POST" action="{{ request()->fullUrl() }}" class="mt-6">@csrf<button class="btn-primary w-full">{{ __('booking.waitlist.claim') }}</button></form>
            @else
                <h1 class="mt-3 text-2xl font-semibold">{{ __('booking.waitlist.gone') }}</h1>
                <p class="mt-3 text-sm text-muted">{{ __('booking.waitlist.gone_sub') }}</p>
                <a href="{{ url('/book') }}" class="btn-primary mt-6">{{ __('booking.account.book_now') }}</a>
            @endif
        </div>
    </section>
</x-layouts.site>
