@php($dir = $rtl ? 'rtl' : 'ltr')
@php($end = $rtl ? 'left' : 'right')
@php($cur = $tenant?->currency ?? 'QAR')
@php($tz = $shift->branch?->timezone ?? config('app.timezone'))
<html dir="{{ $dir }}">
<head>
<style>
    body { font-family: dejavusans; color: #1c1a17; font-size: 10pt; }
    h1 { font-size: 20pt; font-weight: normal; margin: 0; }
    .muted { color: #7a736a; }
    .gold { color: #a88b4f; }
    table { width: 100%; border-collapse: collapse; }
    .grid td { padding: 7px 4px; border-bottom: 1px solid #f0ebe3; }
    .grid th { text-align: {{ $rtl ? 'right' : 'left' }}; font-size: 8pt; text-transform: uppercase; color: #7a736a; border-bottom: 1px solid #ece6dd; padding: 6px 4px; }
    .num { text-align: {{ $end }}; }
    .box { border: 1px solid #ece6dd; padding: 12px; }
    .big { font-size: 15pt; }
    .neg { color: #a4473f; }
    .pos { color: #4f7a5a; }
</style>
</head>
<body>
<table>
    <tr>
        <td style="width:60%">
            <h1>{{ __('pos.shift.z_report') }}</h1>
            <p class="muted">{{ $tenant?->name }} · {{ $shift->branch?->name }} · {{ $shift->register?->name }}</p>
        </td>
        <td class="num" style="width:40%">
            <p class="big" style="margin:0">#{{ $shift->id }}</p>
            <p class="muted" style="margin:2px 0">{{ __('pos.shift.opened_at') }}: {{ $shift->opened_at?->setTimezone($tz)->format('Y-m-d H:i') }} · {{ $shift->user?->name }}</p>
            @if ($shift->closed_at)<p class="muted" style="margin:2px 0">{{ __('pos.shift.closed_at') }}: {{ $shift->closed_at->setTimezone($tz)->format('Y-m-d H:i') }} · {{ $shift->closer?->name }}</p>@endif
        </td>
    </tr>
</table>

<table style="margin-top:18px">
    <tr>
        <td class="box" style="width:33%"><span class="muted">{{ __('pos.shift.expected') }}</span><br><span class="big">{{ money((int) ($shift->expected_cash ?? $summary['expected_cash']), $cur) }}</span></td>
        <td class="box" style="width:33%"><span class="muted">{{ __('pos.shift.counted') }}</span><br><span class="big">{{ $shift->counted_cash !== null ? money((int) $shift->counted_cash, $cur) : '—' }}</span></td>
        <td class="box" style="width:34%"><span class="muted">{{ __('pos.shift.variance') }}</span><br>
            <span class="big {{ ($shift->variance ?? 0) < 0 ? 'neg' : 'pos' }}">{{ $shift->variance !== null ? money((int) $shift->variance, $cur) : '—' }}</span></td>
    </tr>
</table>

<h3 class="gold" style="margin-top:22px">{{ __('pos.shift.cash_drawer') }}</h3>
<table class="grid">
    <tr><td>{{ __('pos.shift.opening_float') }}</td><td class="num">{{ money($summary['opening_float'], $cur) }}</td></tr>
    <tr><td>+ {{ __('pos.shift.cash_sales') }}</td><td class="num">{{ money($summary['cash_sales'], $cur) }}</td></tr>
    <tr><td>− {{ __('pos.shift.cash_refunds') }}</td><td class="num">{{ money($summary['cash_refunds'], $cur) }}</td></tr>
    <tr><td>− {{ __('pos.shift.cash_out') }}</td><td class="num">{{ money($summary['cash_out'], $cur) }}</td></tr>
    <tr><td>+ {{ __('pos.shift.cash_in') }}</td><td class="num">{{ money($summary['cash_in'], $cur) }}</td></tr>
    <tr><td><b>= {{ __('pos.shift.expected') }}</b></td><td class="num"><b>{{ money($summary['expected_cash'], $cur) }}</b></td></tr>
</table>

<h3 class="gold" style="margin-top:22px">{{ __('pos.shift.sales') }}</h3>
<table class="grid">
    <tr><td>{{ __('pos.shift.sales_count') }}</td><td class="num">{{ $summary['sales_count'] }}</td></tr>
    <tr><td>{{ __('pos.ticket.subtotal') }}</td><td class="num">{{ money($summary['gross'], $cur) }}</td></tr>
    <tr><td>{{ __('pos.ticket.discount') }}</td><td class="num">−{{ money($summary['discounts'], $cur) }}</td></tr>
    <tr><td>{{ __('pos.shift.tax') }}</td><td class="num">{{ money($summary['tax'], $cur) }}</td></tr>
    <tr><td>{{ __('pos.ticket.tips') }}</td><td class="num">{{ money($summary['tips'], $cur) }}</td></tr>
    <tr><td><b>{{ __('pos.shift.net_sales') }}</b></td><td class="num"><b>{{ money($summary['net_sales'], $cur) }}</b></td></tr>
    <tr><td>{{ __('pos.shift.refunds') }} ({{ $summary['refunds_count'] }})</td><td class="num">−{{ money($summary['refunds_total'], $cur) }}</td></tr>
    <tr><td>{{ __('pos.shift.voids') }}</td><td class="num">{{ $summary['voids_count'] }}</td></tr>
</table>

<h3 class="gold" style="margin-top:22px">{{ __('pos.shift.by_method') }}</h3>
<table class="grid">
    <thead><tr><th>{{ __('pos.shift.method') }}</th><th class="num">#</th><th class="num">{{ __('pos.ticket.total') }}</th></tr></thead>
    @forelse ($summary['by_method'] as $method => $row)
        <tr><td>{{ $method === 'deposit' ? __('pos.pay.deposit_paid') : __('salon.payments.methods.'.$method) }}</td><td class="num">{{ $row['count'] }}</td><td class="num">{{ money($row['total'], $cur) }}</td></tr>
    @empty
        <tr><td colspan="3" class="muted">{{ __('pos.shift.no_sales') }}</td></tr>
    @endforelse
</table>

@if ($shift->cashMovements->isNotEmpty())
    <h3 class="gold" style="margin-top:22px">{{ __('pos.shift.movements') }}</h3>
    <table class="grid">
        @foreach ($shift->cashMovements as $m)
            <tr><td>{{ $m->created_at->setTimezone($tz)->format('H:i') }}</td><td>{{ __('pos.shift.movement_'.$m->type) }}</td><td>{{ $m->reason }}</td><td class="num">{{ ($m->type === 'out' ? '−' : '+').money((int) $m->amount, $cur) }}</td></tr>
        @endforeach
    </table>
@endif

@if ($shift->notes)<p class="muted" style="margin-top:16px">{{ $shift->notes }}</p>@endif
<p class="muted" style="margin-top:28px; font-size:8pt">{{ __('pos.shift.generated', ['date' => now()->setTimezone($tz)->format('Y-m-d H:i')]) }}</p>
</body>
</html>
