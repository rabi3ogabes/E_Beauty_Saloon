@props(['title' => null, 'description' => null])
@php($rtl = in_array(app()->getLocale(), config('salon.rtl_locales'), true))
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('salon.name') }}</title>
    <meta name="description" content="{{ $description ?? __('platform.meta_description') }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:title" content="{{ $title ?? config('salon.name') }}">
    <meta property="og:type" content="website">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Sans+Arabic:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-dvh">
    <header x-data="{ scrolled: false, open: false }" @scroll.window="scrolled = window.scrollY > 8" :class="scrolled ? 'glass shadow-soft' : ''" class="sticky top-0 z-40 transition-all duration-300">
        <div class="container-luxe flex items-center justify-between gap-4 py-4">
            <a href="{{ url('/') }}" class="font-display text-xl font-semibold tracking-tight">{{ config('salon.name') }}</a>
            <nav class="hidden items-center gap-7 text-sm md:flex">
                <a href="{{ url('/#modules') }}" class="text-ink/75 hover:text-ink">{{ __('platform.nav.features') }}</a>
                <a href="{{ url('/pricing') }}" class="text-ink/75 hover:text-ink">{{ __('platform.nav.pricing') }}</a>
                <a href="{{ url('/#studio') }}" class="text-ink/75 hover:text-ink">{{ __('platform.nav.ai') }}</a>
                <a href="{{ url('/demo') }}" class="text-ink/75 hover:text-ink">{{ __('platform.nav.demo') }}</a>
            </nav>
            <div class="flex items-center gap-2">
                <a href="{{ request()->fullUrlWithQuery(['lang' => app()->getLocale() === 'ar' ? 'en' : 'ar']) }}" class="chip min-h-10 px-3 text-xs">{{ app()->getLocale() === 'ar' ? 'EN' : 'عربي' }}</a>
                <a href="{{ url('/signup') }}" class="btn-primary hidden sm:inline-flex">{{ __('platform.cta.start_trial') }}</a>
            </div>
        </div>
    </header>
    <main id="main">{{ $slot }}</main>
    <footer class="mt-24 border-t hairline">
        <div class="container-luxe flex flex-col items-center justify-between gap-3 py-10 text-sm text-muted sm:flex-row">
            <p>© {{ now()->year }} {{ config('salon.name') }}</p>
            <div class="flex gap-5"><a href="{{ url('/legal/terms') }}">{{ __('platform.footer.terms') }}</a><a href="{{ url('/legal/privacy') }}">{{ __('platform.footer.privacy') }}</a></div>
        </div>
    </footer>
    @stack('scripts')
</body>
</html>
