@php
    $t = tenant();
    $locale = app()->getLocale();
    $rtl = in_array($locale, config('salon.rtl_locales'), true);
    $fontDisplay = $t?->brand('font_display') ?? 'Playfair Display';
    $fontBody = $t?->brand('font_body') ?? 'Inter';
    $fonts = collect([$fontDisplay.':wght@500;600;700', $fontBody.':wght@400;500;600', 'IBM Plex Sans Arabic:wght@400;500;600'])
        ->map(fn ($f) => 'family='.str_replace(' ', '+', $f))->implode('&');
    $themeKey = $t?->brand('theme') ?? 'maison';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}" class="theme-{{ $themeKey }} {{ $themeKey === 'noir' ? 'dark' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ $t?->name }}</title>
    <meta name="description" content="{{ $description ?? \Illuminate\Support\Str::limit(strip_tags((string) $t?->brand('about')), 155) }}">
    <link rel="canonical" href="{{ url()->current() }}">
    @foreach (config('salon.locales') as $code => $label)
        <link rel="alternate" hreflang="{{ $code }}" href="{{ url()->current() }}?lang={{ $code }}">
    @endforeach
    <meta property="og:title" content="{{ $title ?? $t?->name }}">
    <meta property="og:site_name" content="{{ $t?->name }}">
    <meta property="og:type" content="website">
    @if ($t?->brand('hero_path'))<meta property="og:image" content="{{ asset('storage/'.$t->brand('hero_path')) }}">@endif
    @if ($t?->brand('favicon_path'))<link rel="icon" href="{{ asset('storage/'.$t->brand('favicon_path')) }}">@endif
    <link rel="manifest" href="{{ url('/manifest.webmanifest') }}">
    <meta name="theme-color" content="{{ $t?->brand('background') ?? '#FAF7F2' }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?{{ $fonts }}&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-primary: {{ e($t?->brand('primary') ?? '#C8A96A') }};
            --brand-accent: {{ e($t?->brand('accent') ?? '#E9D6CF') }};
            --brand-font-display: '{{ e($fontDisplay) }}';
            --brand-font-body: '{{ e($fontBody) }}';
            --brand-radius: {{ ($t?->brand('button_style') ?? 'pill') === 'pill' ? '999px' : '12px' }};
        }
        .btn { border-radius: var(--brand-radius); }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
    {!! $t?->setting('website.head_scripts') !!}
</head>
<body class="min-h-dvh">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50 btn-primary">{{ __('site.skip_to_content') }}</a>

    <x-site.header />

    <main id="main">
        {{ $slot ?? '' }}
        @yield('content')
    </main>

    <x-site.footer />

    @stack('scripts')
</body>
</html>
