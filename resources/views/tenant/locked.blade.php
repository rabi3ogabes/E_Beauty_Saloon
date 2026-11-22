<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('salon.billing.suspended_subject') }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Inter:wght@400;500&family=IBM+Plex+Sans+Arabic:wght@400;500&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-dvh items-center justify-center bg-ivory p-6">
    <div class="card w-full max-w-lg p-10">
        <p class="eyebrow">{{ $tenant->name }}</p>
        <h1 class="mt-3 text-3xl font-semibold">{{ __('salon.billing.suspended_subject') }}</h1>
        <p class="mt-3 text-muted">{{ __('salon.billing.suspended_line') }}</p>
        @if ($invoice)
            <div class="mt-8 flex items-center justify-between rounded-2xl border hairline p-5">
                <div><p class="text-sm text-muted">{{ $invoice->number }}</p><p class="font-display text-2xl">{{ money($invoice->balanceDue(), $invoice->currency) }}</p></div>
                <a href="{{ url('/dashboard/billing') }}" class="btn-gold">{{ __('salon.billing.pay_now') }}</a>
            </div>
        @endif
        <form method="POST" action="{{ url('/dashboard/logout') }}" class="mt-6">@csrf<button class="text-sm text-muted underline underline-offset-4">Sign out</button></form>
    </div>
</body>
</html>
