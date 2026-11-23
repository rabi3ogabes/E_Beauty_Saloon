@props(['tenant'])
@php($ids = \App\Domain\Websites\Support\SiteSeo::analytics($tenant))
{{-- Analytics / pixels: only validated IDs are injected, loaded after the page is interactive. --}}
@if ($ids['ga4'] || $ids['meta'] || $ids['tiktok'])
<script>
    window.addEventListener('load', function () {
        setTimeout(function () {
            @if ($ids['ga4'])
            (function (id) {
                var s = document.createElement('script'); s.async = true; s.src = 'https://www.googletagmanager.com/gtag/js?id=' + id; document.head.appendChild(s);
                window.dataLayer = window.dataLayer || []; window.gtag = function () { dataLayer.push(arguments); }; gtag('js', new Date()); gtag('config', id);
            })(@js($ids['ga4']));
            @endif
            @if ($ids['meta'])
            (function (f, b, e, v, n, t, s) { if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments) };
                if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = []; t = b.createElement(e); t.async = !0; t.src = v;
                s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s) })(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', @js($ids['meta'])); fbq('track', 'PageView');
            @endif
            @if ($ids['tiktok'])
            (function (w, d, t) { w.TiktokAnalyticsObject = t; var ttq = w[t] = w[t] || []; ttq.methods = ['page', 'track', 'identify', 'instances', 'debug', 'on', 'off', 'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie'];
                ttq.setAndDefer = function (t, e) { t[e] = function () { t.push([e].concat(Array.prototype.slice.call(arguments, 0))) } };
                for (var i = 0; i < ttq.methods.length; i++) ttq.setAndDefer(ttq, ttq.methods[i]);
                ttq.load = function (e) { var s = d.createElement('script'); s.async = true; s.src = 'https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=' + e + '&lib=' + t; d.head.appendChild(s) };
                ttq.load(@js($ids['tiktok'])); ttq.page(); })(window, document, 'ttq');
            @endif
        }, 1500);
    });
</script>
@endif
