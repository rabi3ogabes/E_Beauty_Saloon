@php($brand = $tenant?->brand('primary') ?? '#C8A96A')
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;background:#FAF7F2;font-family:Inter,'IBM Plex Sans Arabic',Helvetica,Arial,sans-serif;color:#1C1A17">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 12px">
<tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border:1px solid #ECE6DD;border-radius:20px;overflow:hidden">
        <tr><td style="padding:28px 32px 8px;text-align:center">
            @if ($tenant?->brand('logo_path'))
                <img src="{{ asset('storage/'.$tenant->brand('logo_path')) }}" alt="{{ $tenant->name }}" height="36">
            @else
                <span style="font-family:'Playfair Display',Georgia,serif;font-size:22px">{{ $tenant?->name ?? config('salon.name') }}</span>
            @endif
        </td></tr>
        @if ($mediaUrl)
            <tr><td><img src="{{ $mediaUrl }}" alt="" width="560" style="display:block;width:100%;height:auto"></td></tr>
        @endif
        <tr><td style="padding:24px 32px;font-size:15px;line-height:1.7">{!! nl2br(e(strip_tags($body))) !!}</td></tr>
        @if (! empty($buttons))
            <tr><td style="padding:0 32px 28px;text-align:center">
                @foreach ($buttons as $button)
                    @if (($button['type'] ?? 'url') === 'url')
                        <a href="{{ $button['url'] }}" style="display:inline-block;margin:4px;padding:12px 26px;border-radius:999px;background:#1C1A17;color:#FAF7F2;text-decoration:none;font-size:14px">{{ $button['text'] }}</a>
                    @endif
                @endforeach
            </td></tr>
        @endif
        <tr><td style="padding:18px 32px;border-top:1px solid #ECE6DD;font-size:12px;color:#7A736A;text-align:center">
            <span style="color:{{ $brand }}">✦</span> {{ $tenant?->name }}
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
