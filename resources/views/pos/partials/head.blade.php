<link rel="manifest" href="{{ route('pos.manifest') }}">
<meta name="theme-color" content="#1C1A17">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="{{ route('pos.icon') }}">
@vite(['resources/css/pos.css', 'resources/js/pos-offline.js'])
