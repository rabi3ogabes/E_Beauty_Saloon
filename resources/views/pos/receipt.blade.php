<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $tenant->name }} · {{ $sale->number }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,600|ibm-plex-sans-arabic:400,600|playfair-display:500" rel="stylesheet">
    <style>
        /* 80mm thermal roll: printable width ≈ 72mm */
        @page { size: 80mm auto; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; background: #faf7f2; color: #1c1a17; }
        body { font-family: Inter, 'IBM Plex Sans Arabic', system-ui, sans-serif; font-size: 12px; line-height: 1.45; }
        [dir=rtl] body { font-family: 'IBM Plex Sans Arabic', Inter, sans-serif; }
        .paper { width: 80mm; max-width: 100%; margin: 16px auto; padding: 5mm 4mm 8mm; background: #fff; border-radius: 16px; box-shadow: 0 8px 24px rgba(28,26,23,.06); }
        .r-head { text-align: center; padding-bottom: 8px; border-bottom: 1px dashed #d9d0c3; margin-bottom: 8px; }
        .r-logo { max-height: 42px; max-width: 60%; margin-bottom: 4px; }
        .r-salon { font-family: 'Playfair Display', Georgia, serif; font-size: 18px; letter-spacing: .5px; }
        .r-muted { color: #7a736a; font-size: 10.5px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 2px 0; vertical-align: top; }
        .r-end { text-align: end; white-space: nowrap; padding-inline-start: 6px; }
        .r-items { margin: 8px 0; border-top: 1px dashed #d9d0c3; border-bottom: 1px dashed #d9d0c3; }
        .r-items td { padding: 5px 0; }
        .r-amount { font-variant-numeric: tabular-nums; }
        .r-totals td { font-variant-numeric: tabular-nums; }
        .r-grand td { font-size: 15px; font-weight: 600; padding-top: 6px; border-top: 1px solid #1c1a17; }
        .r-stamp { margin: 10px auto; text-align: center; border: 2px solid #a4473f; color: #a4473f; border-radius: 10px; padding: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: 2px; }
        .r-qr { text-align: center; margin-top: 12px; }
        .r-foot { text-align: center; margin-top: 8px; font-size: 11px; }
        .actions { width: 80mm; max-width: 100%; margin: 0 auto 24px; display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
        .actions a, .actions button { min-height: 48px; padding: 0 20px; border-radius: 999px; border: 1px solid #ece6dd; background: #fff; color: #1c1a17; font: inherit; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; cursor: pointer; }
        .actions .gold { background: #1c1a17; color: #c8a96a; border-color: #1c1a17; }
        @media print {
            html, body { background: #fff; }
            .paper { margin: 0; width: 72mm; padding: 0 0 6mm; border-radius: 0; box-shadow: none; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <main class="paper">
        @include('pos.partials.receipt-body')
    </main>
    <div class="actions">
        <button type="button" class="gold" onclick="window.print()">{{ __('pos.receipt.print') }}</button>
        @if (empty($public))
            <a href="{{ route('pos.receipt.pdf', ['sale' => $sale->id, 'locale' => $locale]) }}">PDF</a>
            <a href="{{ request()->fullUrlWithQuery(['locale' => $locale === 'ar' ? 'en' : 'ar', 'preview' => 1]) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
        @else
            <a href="{{ request()->fullUrlWithQuery(['locale' => $locale === 'ar' ? 'en' : 'ar']) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
            <a href="{{ $qrUrl }}">{{ $qrLabel }}</a>
        @endif
    </div>
    @if (! empty($autoPrint))
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 250));</script>
    @endif
</body>
</html>
