@php
    use App\Domain\Websites\Support\SiteSeo;
    use App\Domain\Websites\Support\ThemeRegistry;

    $t = tenant();
    $locale = app()->getLocale();
    $rtl = in_array($locale, config('salon.rtl_locales'), true);
    $bare = (bool) ($bare ?? false);
    $theme = ThemeRegistry::for($t);
    $fontDisplay = (string) ($t?->brand('font_display') ?? $theme['font_display']);
    $fontBody = (string) ($t?->brand('font_body') ?? $theme['font_body']);
    $families = $rtl
        ? ['IBM Plex Sans Arabic:wght@400;500;600']
        : [$fontDisplay.':wght@500;600;700', $fontBody.':wght@400;500;600'];
    $fontsUrl = 'https://fonts.googleapis.com/css2?'.collect($families)->unique()->map(fn ($f) => 'family='.str_replace(' ', '+', $f))->implode('&').'&display=swap';
    $pageTitle = (isset($title) && $title ? $title.' · ' : '').$t?->name;
    $metaDescription = $description ?? (string) ($t?->setting('website.seo_description') ?: \Illuminate\Support\Str::limit(strip_tags((string) $t?->brand('about')), 155));
    $og = $ogImage ?? ($t?->brand('hero_path') ? asset('storage/'.$t->brand('hero_path')) : null);
    $canonical = url()->current();
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}" class="theme-{{ $theme['key'] }} {{ $theme['dark'] ? 'dark' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    @if (! empty($noindex))<meta name="robots" content="noindex">@endif
    <link rel="canonical" href="{{ $canonical }}">
    @foreach (config('salon.locales') as $code => $label)
        <link rel="alternate" hreflang="{{ $code }}" href="{{ $canonical }}?lang={{ $code }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ $canonical }}">
    <meta property="og:title" content="{{ $title ?? $t?->name }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:site_name" content="{{ $t?->name }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="{{ $locale === 'ar' ? 'ar_QA' : 'en_QA' }}">
    @if ($og)<meta property="og:image" content="{{ $og }}"><meta name="twitter:card" content="summary_large_image">@endif
    <link rel="icon" href="{{ $t?->brand('favicon_path') ? asset('storage/'.$t->brand('favicon_path')) : url('/pwa/icon-192.png') }}">
    <link rel="apple-touch-icon" href="{{ url('/pwa/icon-192.png') }}">
    <link rel="manifest" href="{{ url('/manifest.webmanifest') }}">
    <meta name="theme-color" content="{{ $theme['dark'] ? '#141014' : ($t?->brand('background') ?? '#FAF7F2') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Non-blocking web fonts (system fallback first paint, no layout shift thanks to size-adjusted fallbacks). --}}
    <link rel="preload" as="style" href="{{ $fontsUrl }}">
    <link rel="stylesheet" href="{{ $fontsUrl }}" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="{{ $fontsUrl }}"></noscript>
    <style>
        :root {
            --brand-primary: {{ SiteSeo::cssColor($t?->brand('primary'), $theme['primary']) }};
            --brand-accent: {{ SiteSeo::cssColor($t?->brand('accent'), $theme['accent']) }};
            --brand-ink: {{ SiteSeo::cssColor($t?->brand('ink'), $theme['ink']) }};
            --brand-bg: {{ SiteSeo::cssColor($theme['dark'] ? $theme['background'] : $t?->brand('background'), $theme['background']) }};
            --brand-font-display: '{{ SiteSeo::cssFont($fontDisplay) }}';
            --brand-font-body: '{{ SiteSeo::cssFont($fontBody) }}';
            --brand-radius: {{ ($t?->brand('button_style') ?? 'pill') === 'pill' ? '999px' : (($t?->brand('button_style') ?? '') === 'square' ? '4px' : '12px') }};
        }
        .btn { border-radius: var(--brand-radius); }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
    @if ($t)
        <x-site.analytics :tenant="$t" />
    @endif
</head>
<body class="min-h-dvh">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50 btn-primary">{{ __('site.skip_to_content') }}</a>

    @unless ($bare)
        <x-site.header />
    @endunless

    <main id="main">
        {{ $slot ?? '' }}
        @yield('content')
    </main>

    @unless ($bare)
        <x-site.footer />
    @endunless

    @stack('scripts')
    <script>
        if ('serviceWorker' in navigator && location.protocol === 'https:') {
            addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
        }
    </script>
</body>
</html>
