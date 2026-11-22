{{-- Fonts + tenant brand tokens for Filament panels --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600&display=swap" rel="stylesheet">
<meta name="theme-color" content="#FAF7F2" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#141014" media="(prefers-color-scheme: dark)">
@if ($t = tenant())
    <style>
        :root { --brand-primary: {{ e($t->brand('primary')) }}; --brand-accent: {{ e($t->brand('accent')) }}; }
    </style>
@endif
