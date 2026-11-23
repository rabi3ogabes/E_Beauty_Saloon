<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Redirecting…</title>@vite(['resources/css/app.css'])</head>
<body class="flex min-h-dvh items-center justify-center bg-ivory">
    <form id="pay" method="POST" action="{{ $action }}" class="card w-full max-w-sm p-8 text-center">
        @foreach ($fields as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        <div class="skeleton mx-auto h-3 w-40"></div>
        <p class="mt-5 text-sm text-muted">{{ __('salon.payments.pending_body') }}</p>
        <noscript><button class="btn-primary mt-6">{{ __('salon.ui.continue') }}</button></noscript>
    </form>
    <script>document.getElementById('pay').submit();</script>
</body></html>
