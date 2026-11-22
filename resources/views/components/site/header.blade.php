@php
    $t = tenant();
    $links = collect([
        ['label' => __('site.nav.services'), 'href' => url('/#services'), 'show' => true],
        ['label' => __('site.nav.book'), 'href' => url('/book'), 'show' => $t?->hasModule('booking')],
        ['label' => __('site.nav.shop'), 'href' => url('/shop'), 'show' => $t?->hasModule('shop')],
        ['label' => __('site.nav.studio'), 'href' => url('/studio'), 'show' => $t?->hasModule('ai_studio')],
        ['label' => __('site.nav.gift_cards'), 'href' => url('/gift-cards'), 'show' => true],
    ])->merge(
        // Owner pages flagged "show in menu" (website builder).
        $t ? \App\Domain\Websites\Models\SitePage::query()->where('in_menu', true)->where('is_published', true)->where('is_home', false)->orderBy('sort')->limit(3)->get()
            ->map(fn ($p) => ['label' => $p->title, 'href' => url('/pages/'.$p->slug), 'show' => true]) : []
    )->where('show', true);
@endphp
<header x-data="{ open: false, scrolled: false }" x-init="scrolled = window.scrollY > 8" @scroll.window="scrolled = window.scrollY > 8"
        :class="scrolled ? 'glass shadow-soft' : 'bg-transparent'"
        class="sticky top-0 z-40 transition-all duration-300">
    <div class="container-luxe flex h-18 items-center justify-between gap-4 py-3">
        <a href="{{ url('/') }}" class="flex items-center gap-3" aria-label="{{ $t?->name }}">
            @if ($t?->brand('logo_path'))
                <img src="{{ asset('storage/'.$t->brand('logo_path')) }}" alt="{{ $t->name }}" class="h-9 w-auto dark:hidden">
                <img src="{{ asset('storage/'.($t->brand('logo_dark_path') ?? $t->brand('logo_path'))) }}" alt="" class="hidden h-9 w-auto dark:block">
            @else
                <span class="font-display text-xl font-semibold tracking-tight">{{ $t?->name }}</span>
            @endif
        </a>

        <nav class="hidden items-center gap-7 text-sm md:flex" aria-label="{{ __('site.nav.primary') }}">
            @foreach ($links as $link)
                <a href="{{ $link['href'] }}" class="text-ink/75 transition hover:text-ink dark:text-ivory/75 dark:hover:text-ivory">{{ $link['label'] }}</a>
            @endforeach
        </nav>

        <div class="flex items-center gap-2">
            <a href="{{ request()->fullUrlWithQuery(['lang' => app()->getLocale() === 'ar' ? 'en' : 'ar']) }}" class="chip min-h-10 px-3 text-xs" lang="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                {{ app()->getLocale() === 'ar' ? 'EN' : 'عربي' }}
            </a>
            <a href="{{ url('/account') }}" class="chip hidden min-h-10 px-3 sm:inline-flex" aria-label="{{ __('site.nav.account') }}">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.1a7.5 7.5 0 0 1 15 0A17.9 17.9 0 0 1 12 21.75c-2.68 0-5.22-.58-7.5-1.65Z"/></svg>
            </a>
            @if ($t?->hasModule('booking'))
                <a href="{{ url('/book') }}" class="btn-primary hidden sm:inline-flex">{{ __('site.cta.book_now') }}</a>
            @endif
            <button type="button" class="chip min-h-11 px-3 md:hidden" @click="open = !open" :aria-expanded="open" aria-controls="mobile-nav" aria-label="{{ __('site.nav.menu') }}">
                <svg x-show="!open" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
                <svg x-show="open" x-cloak class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
    </div>

    <div id="mobile-nav" x-show="open" x-cloak x-transition.opacity.duration.200ms class="glass border-t hairline md:hidden">
        <nav class="container-luxe flex flex-col gap-1 py-4">
            @foreach ($links as $link)
                <a href="{{ $link['href'] }}" class="rounded-xl px-3 py-3 text-base hover:bg-white/70 dark:hover:bg-white/5">{{ $link['label'] }}</a>
            @endforeach
            <a href="{{ url('/account') }}" class="rounded-xl px-3 py-3 text-base hover:bg-white/70">{{ __('site.nav.account') }}</a>
            @if ($t?->hasModule('booking'))
                <a href="{{ url('/book') }}" class="btn-primary mt-2">{{ __('site.cta.book_now') }}</a>
            @endif
        </nav>
    </div>
</header>
