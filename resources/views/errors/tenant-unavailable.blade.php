<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('salon.unavailable.title') }} · {{ $tenant->name }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Inter:wght@400;500&family=IBM+Plex+Sans+Arabic:wght@400;500&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-dvh items-center justify-center bg-ivory p-6">
    <div class="card max-w-md p-10 text-center">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-gold/15 text-2xl text-gold-dark">✦</div>
        <h1 class="mt-6 text-3xl font-semibold">{{ __('salon.unavailable.title') }}</h1>
        <p class="mt-3 text-muted">{{ __('salon.unavailable.body', ['salon' => $tenant->name]) }}</p>
        @if ($tenant->phone)
            <a href="tel:{{ $tenant->phone }}" class="btn-primary mt-8" dir="ltr">{{ $tenant->phone }}</a>
        @endif
    </div>
</body>
</html>
