@props(['title' => null, 'description' => null, 'ogImage' => null, 'noindex' => false])
@php
    $locale = app()->getLocale();
    $rtl = in_array($locale, config('salon.rtl_locales'), true);
    $brand = config('salon.name');
    $fonts = $rtl
        ? 'https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600&display=swap'
        : 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Inter:wght@400;500;600&display=swap';
    $canonical = url()->current();
    $modulesNav = \App\Domain\Billing\Models\Module::where('is_active', true)->orderBy('sort')->get(['key', 'name']);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ $brand }}</title>
    <meta name="description" content="{{ $description ?? __('platform.meta_description') }}">
    @if ($noindex)<meta name="robots" content="noindex">@endif
    <link rel="canonical" href="{{ $canonical }}">
    @foreach (config('salon.locales') as $code => $label)
        <link rel="alternate" hreflang="{{ $code }}" href="{{ $canonical }}?lang={{ $code }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ $canonical }}">
    <meta property="og:title" content="{{ $title ?? $brand }}">
    <meta property="og:description" content="{{ $description ?? __('platform.meta_description') }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:site_name" content="{{ $brand }}">
    @if ($ogImage)<meta property="og:image" content="{{ $ogImage }}"><meta name="twitter:card" content="summary_large_image">@endif
    <meta name="theme-color" content="#FAF7F2">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="{{ $fonts }}">
    <link rel="stylesheet" href="{{ $fonts }}" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="{{ $fonts }}"></noscript>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {!! \App\Domain\Websites\Support\SiteSeo::jsonLd(\App\Domain\Websites\Support\SiteSeo::platform()) !!}
    @stack('head')
</head>
<body class="min-h-dvh bg-ivory">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50 btn-primary">{{ __('site.skip_to_content') }}</a>
    <header x-data="{ scrolled: false, open: false }" x-init="scrolled = window.scrollY > 8" @scroll.window="scrolled = window.scrollY > 8"
            :class="scrolled ? 'glass shadow-soft' : 'border-transparent'" class="sticky top-0 z-40 border-b border-transparent transition-all duration-300">
        <div class="container-luxe flex h-18 items-center justify-between gap-4 py-3">
            <a href="{{ url('/') }}" class="flex items-center gap-2 font-display text-xl font-semibold tracking-tight">
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-ink text-sm text-gold" aria-hidden="true">✦</span>{{ $brand }}
            </a>
            <nav class="hidden items-center gap-7 text-sm lg:flex" aria-label="{{ __('site.nav.primary') }}">
                <div x-data="{ m: false }" class="relative" @mouseleave="m = false">
                    <button type="button" class="text-ink/75 hover:text-ink" @mouseenter="m = true" @click="m = !m" :aria-expanded="m">{{ __('platform.nav.features') }} ▾</button>
                    <div x-show="m" x-cloak x-transition.opacity class="glass absolute start-0 top-8 grid w-72 gap-1 rounded-2xl p-3 shadow-lift">
                        @foreach ($modulesNav as $m)
                            <a href="{{ url('/features/'.$m->key) }}" class="rounded-xl px-3 py-2 hover:bg-white/80">{{ $m->name }}</a>
                        @endforeach
                    </div>
                </div>
                <a href="{{ url('/pricing') }}" class="text-ink/75 hover:text-ink">{{ __('platform.nav.pricing') }}</a>
                <a href="{{ url('/features/ai_studio') }}" class="text-ink/75 hover:text-ink">{{ __('platform.nav.ai') }}</a>
                <a href="{{ url('/blog') }}" class="text-ink/75 hover:text-ink">{{ __('platform.nav.blog') }}</a>
                <a href="{{ url('/help') }}" class="text-ink/75 hover:text-ink">{{ __('platform.nav.help') }}</a>
            </nav>
            <div class="flex items-center gap-2">
                <a href="{{ request()->fullUrlWithQuery(['lang' => $locale === 'ar' ? 'en' : 'ar']) }}" class="chip min-h-10 px-3 text-xs" lang="{{ $locale === 'ar' ? 'en' : 'ar' }}">{{ $locale === 'ar' ? 'EN' : 'عربي' }}</a>
                <a href="{{ url('/demo') }}" class="btn-ghost hidden md:inline-flex">{{ __('platform.nav.demo') }}</a>
                <a href="{{ url('/signup') }}" class="btn-primary hidden sm:inline-flex">{{ __('platform.cta.start_trial') }}</a>
                <button type="button" class="chip min-h-11 px-3 lg:hidden" @click="open = !open" :aria-expanded="open" aria-label="{{ __('site.nav.menu') }}">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
            </div>
        </div>
        <div x-show="open" x-cloak x-transition.opacity class="glass border-t hairline lg:hidden">
            <nav class="container-luxe flex flex-col gap-1 py-4 text-base">
                @foreach ($modulesNav as $m)<a href="{{ url('/features/'.$m->key) }}" class="rounded-xl px-3 py-2.5">{{ $m->name }}</a>@endforeach
                <a href="{{ url('/pricing') }}" class="rounded-xl px-3 py-2.5">{{ __('platform.nav.pricing') }}</a>
                <a href="{{ url('/blog') }}" class="rounded-xl px-3 py-2.5">{{ __('platform.nav.blog') }}</a>
                <a href="{{ url('/help') }}" class="rounded-xl px-3 py-2.5">{{ __('platform.nav.help') }}</a>
                <a href="{{ url('/demo') }}" class="btn-ghost mt-2">{{ __('platform.nav.demo') }}</a>
                <a href="{{ url('/signup') }}" class="btn-primary mt-2">{{ __('platform.cta.start_trial') }}</a>
            </nav>
        </div>
    </header>

    <main id="main">{{ $slot }}</main>

    <footer class="mt-24 border-t hairline bg-white/40">
        <div class="container-luxe grid gap-10 py-14 sm:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <p class="font-display text-2xl">{{ $brand }}</p>
                <p class="mt-3 max-w-sm text-sm text-muted">{{ __('platform.meta_description') }}</p>
            </div>
            <div>
                <p class="eyebrow">{{ __('platform.footer.product') }}</p>
                <ul class="mt-4 space-y-2.5 text-sm">
                    @foreach ($modulesNav->take(5) as $m)<li><a href="{{ url('/features/'.$m->key) }}" class="hover:text-gold-dark">{{ $m->name }}</a></li>@endforeach
                </ul>
            </div>
            <div>
                <p class="eyebrow">{{ __('platform.footer.company') }}</p>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li><a href="{{ url('/pricing') }}" class="hover:text-gold-dark">{{ __('platform.nav.pricing') }}</a></li>
                    <li><a href="{{ url('/blog') }}" class="hover:text-gold-dark">{{ __('platform.nav.blog') }}</a></li>
                    <li><a href="{{ url('/demo') }}" class="hover:text-gold-dark">{{ __('platform.nav.demo') }}</a></li>
                    @foreach (\App\Domain\Websites\Models\PlatformPage::where('is_published', true)->where('in_footer', true)->orderBy('sort')->get() as $p)
                        <li><a href="{{ url('/p/'.$p->slug) }}" class="hover:text-gold-dark">{{ $p->title }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div>
                <p class="eyebrow">{{ __('platform.footer.support') }}</p>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li><a href="{{ url('/help') }}" class="hover:text-gold-dark">{{ __('platform.nav.help') }}</a></li>
                    <li><a href="{{ url('/legal/terms') }}" class="hover:text-gold-dark">{{ __('platform.footer.terms') }}</a></li>
                    <li><a href="{{ url('/legal/privacy') }}" class="hover:text-gold-dark">{{ __('platform.footer.privacy') }}</a></li>
                </ul>
            </div>
        </div>
        <div class="border-t hairline">
            <div class="container-luxe flex flex-col items-center justify-between gap-2 py-6 text-xs text-muted sm:flex-row">
                <p>© {{ now()->year }} {{ $brand }}. {{ __('site.footer.rights') }}</p>
                <p>{{ __('platform.footer.made_in') }}</p>
            </div>
        </div>
    </footer>
    @stack('scripts')
</body>
</html>
