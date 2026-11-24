<!doctype html>
<html><body style="margin:0;background:#F7F3EC;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1C1A17">
<div style="max-width:640px;margin:0 auto;padding:28px 20px">
    <p style="margin:0 0 6px;font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:#A4473F">Server error · {{ $c->environment }}</p>
    <h1 style="margin:0 0 4px;font-size:20px;line-height:1.35">{{ class_basename($c->class) }}</h1>
    <p style="margin:0 0 18px;font-size:15px;line-height:1.5">{{ $c->message }}</p>

    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:12px;font-size:13px" cellpadding="0" cellspacing="0">
        <tr><td style="padding:10px 14px;color:#7A736A;width:90px">When</td><td style="padding:10px 14px">{{ $c->time }}</td></tr>
        <tr><td style="padding:10px 14px;color:#7A736A;border-top:1px solid #EFE8DC">Where</td><td style="padding:10px 14px;border-top:1px solid #EFE8DC;font-family:Menlo,Consolas,monospace">{{ $c->location }}</td></tr>
        @foreach ($c->request as $label => $value)
            <tr><td style="padding:10px 14px;color:#7A736A;border-top:1px solid #EFE8DC">{{ ucfirst($label) }}</td><td style="padding:10px 14px;border-top:1px solid #EFE8DC;word-break:break-all">{{ $value }}</td></tr>
        @endforeach
        <tr><td style="padding:10px 14px;color:#7A736A;border-top:1px solid #EFE8DC">Type</td><td style="padding:10px 14px;border-top:1px solid #EFE8DC;font-family:Menlo,Consolas,monospace">{{ $c->class }}</td></tr>
    </table>

    @if ($c->repeats > 0)
        <p style="margin:14px 0 0;padding:10px 14px;background:#F3EADB;border-radius:10px;font-size:13px">This error also happened <strong>{{ $c->repeats }}</strong> more {{ \Illuminate\Support\Str::plural('time', $c->repeats) }} since the last alert.</p>
    @endif

    <h2 style="margin:22px 0 8px;font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#7A736A">Stack</h2>
    <div style="background:#1C1A17;color:#EDE6D9;border-radius:12px;padding:12px 14px;font-family:Menlo,Consolas,monospace;font-size:12px;line-height:1.6">
        @foreach ($c->frames as $f)
            <div style="{{ $f['app'] ? '' : 'opacity:.55' }}">{{ $f['at'] }} <span style="color:#C8A96A">{{ $f['call'] }}</span></div>
        @endforeach
    </div>

    <p style="margin:18px 0 0;font-size:12px;color:#7A736A">The full error is in the server log (storage/logs). The same error is e-mailed at most once every {{ config('monitoring.throttle_minutes') }} minutes.</p>
</div>
</body></html>
