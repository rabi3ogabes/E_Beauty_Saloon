<x-layouts.site :title="__('marketing.packages.public_title')" :description="__('marketing.packages.public_subtitle')">
    @php($locale = app()->getLocale())
    <section class="container-luxe py-12 sm:py-16">
        <div class="max-w-2xl" data-reveal>
            <p class="eyebrow">{{ __('marketing.packages.eyebrow') }}</p>
            <h1 class="mt-3 text-4xl font-semibold sm:text-5xl">{{ __('marketing.packages.public_title') }}</h1>
            <p class="mt-4 text-lg text-muted">{{ __('marketing.packages.public_subtitle') }}</p>
        </div>

        @if ($errors->any())<p class="mt-6 rounded-xl bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</p>@endif

        @if ($packages->isEmpty() && $memberships->isEmpty())
            <div class="card mt-10 p-10 text-center">
                <p class="font-display text-xl">{{ __('marketing.packages.empty_public') }}</p>
                @if ($currentTenant->hasModule('booking'))<a href="{{ url('/book') }}" class="btn-primary mt-6">{{ __('site.cta.book_now') }}</a>@endif
            </div>
        @endif

        <div class="mt-10 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($packages as $package)
                <article class="card card-hover flex flex-col p-6 sm:p-8" data-reveal>
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="font-display text-2xl font-semibold">{{ $package->getTranslation('name', $locale) }}</h2>
                        @if ($package->sessions_count > 1)<span class="chip shrink-0 text-xs">{{ trans_choice('marketing.packages.sessions', $package->sessions_count, ['count' => $package->sessions_count]) }}</span>@endif
                    </div>
                    @if ($package->getTranslation('description', $locale, false))<p class="mt-3 text-sm text-muted">{{ $package->getTranslation('description', $locale) }}</p>@endif
                    <ul class="mt-5 space-y-2 text-sm">
                        @foreach ($package->items as $item)
                            <li class="flex items-center gap-2"><span class="text-gold-dark">✦</span>{{ $item->quantity > 1 ? $item->quantity.' × ' : '' }}{{ $item->itemable?->getTranslation('name', $locale) }}</li>
                        @endforeach
                    </ul>
                    <div class="mt-auto pt-6">
                        <div class="flex items-baseline gap-3">
                            <p class="font-display text-3xl">{{ money($package->effectivePrice()) }}</p>
                            @if ($package->savings() > 0)<p class="text-sm text-muted line-through">{{ money($package->itemsValue()) }}</p>@endif
                        </div>
                        @if ($package->validity_days)<p class="mt-1 text-xs text-muted">{{ __('marketing.packages.valid_days', ['days' => $package->validity_days]) }}</p>@endif
                        @if ($client)
                            <form method="POST" action="{{ route('marketing.packages.buy', $package) }}" class="mt-5">@csrf<button class="btn-primary w-full">{{ __('marketing.packages.buy') }}</button></form>
                        @else
                            <a href="{{ route('client.login') }}" class="btn-primary mt-5 w-full">{{ __('marketing.packages.sign_in_to_buy') }}</a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>

        @if ($memberships->isNotEmpty())
            <div class="mt-20 max-w-2xl" data-reveal>
                <p class="eyebrow">{{ __('marketing.memberships.eyebrow') }}</p>
                <h2 class="mt-3 text-3xl font-semibold sm:text-4xl">{{ __('marketing.memberships.public_title') }}</h2>
            </div>
            <div class="mt-8 grid gap-6 md:grid-cols-2">
                @foreach ($memberships as $m)
                    <article class="card relative overflow-hidden p-6 sm:p-8" data-reveal>
                        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-gold via-rose to-gold"></div>
                        <h3 class="font-display text-2xl font-semibold">{{ $m->getTranslation('name', $locale) }}</h3>
                        @if ($m->getTranslation('description', $locale, false))<p class="mt-3 text-sm text-muted">{{ $m->getTranslation('description', $locale) }}</p>@endif
                        <p class="mt-5 font-display text-3xl">{{ money($m->monthly_price) }}<span class="text-base text-muted"> {{ __('salon.ui.per_month') }}</span></p>
                        <p class="mt-4 text-sm text-muted">{{ __('marketing.memberships.ask_at_salon') }}</p>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</x-layouts.site>
