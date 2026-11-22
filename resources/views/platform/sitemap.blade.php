{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($urls as $url)
    <url>
        <loc>{{ $url['loc'] }}</loc>
@if ($url['lastmod'])
        <lastmod>{{ $url['lastmod'] }}</lastmod>
@endif
        <priority>{{ $url['priority'] }}</priority>
@if ($url['alternates'])
@foreach (array_keys(config('salon.locales')) as $code)
        <xhtml:link rel="alternate" hreflang="{{ $code }}" href="{{ $url['loc'] }}{{ str_contains($url['loc'], '?') ? '&amp;' : '?' }}lang={{ $code }}"/>
@endforeach
@endif
    </url>
@endforeach
</urlset>
