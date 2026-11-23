{{-- Shared 80mm receipt body (HTML print view + mPDF). Simple tables only: mPDF-friendly. --}}
@php($cur = $sale->currency)
<div class="r-head">
    @if (! empty($logo))<img src="{{ $logo }}" class="r-logo" alt="">@endif
    <div class="r-salon">{{ $tenant->name }}</div>
    @if ($branch)
        <div class="r-muted">{{ $branch->name }}@if ($branch->phone) · <span dir="ltr">{{ $branch->phone }}</span>@endif</div>
        @if ($branch->address)<div class="r-muted">{{ $branch->address }}</div>@endif
    @endif
    @if ($tenant->legal_name)<div class="r-muted">{{ $tenant->legal_name }}</div>@endif
</div>

<table class="r-meta">
    <tr><td>{{ __('pos.receipt.number') }}</td><td class="r-end" dir="ltr">{{ $sale->number }}</td></tr>
    <tr><td>{{ __('pos.receipt.date') }}</td><td class="r-end" dir="ltr">{{ $sale->completed_at?->setTimezone($branch?->timezone ?? config('app.timezone'))->format('Y-m-d H:i') }}</td></tr>
    @if ($sale->cashier)<tr><td>{{ __('pos.receipt.cashier') }}</td><td class="r-end">{{ $sale->cashier->name }}</td></tr>@endif
    @if ($sale->client)<tr><td>{{ __('pos.receipt.client') }}</td><td class="r-end">{{ $sale->client->name }}</td></tr>@endif
</table>

<table class="r-items">
    @foreach ($sale->items as $item)
        <tr>
            <td class="r-item">
                {{ $item->name }}
                <div class="r-muted">{{ $item->qty }} × {{ money((int) $item->unit_price, $cur) }}@if ($item->staff) · {{ $item->staff->name }}@endif</div>
                @if ($item->discount > 0)<div class="r-muted">{{ __('pos.ticket.discount') }} −{{ money((int) $item->discount, $cur) }}</div>@endif
                @if (! empty($item->meta['gift_card_code']))<div class="r-muted" dir="ltr">{{ $item->meta['gift_card_code'] }}</div>@endif
            </td>
            <td class="r-end r-amount">{{ money((int) $item->total, $cur) }}</td>
        </tr>
    @endforeach
</table>

<table class="r-totals">
    <tr><td>{{ __('pos.ticket.subtotal') }}</td><td class="r-end">{{ money((int) $sale->subtotal, $cur) }}</td></tr>
    @if ($sale->discount > 0)<tr><td>{{ __('pos.ticket.discount') }}</td><td class="r-end">−{{ money((int) $sale->discount, $cur) }}</td></tr>@endif
    @if ($sale->tax > 0)<tr><td>{{ $sale->tax_inclusive ? __('pos.ticket.tax_included', ['rate' => rtrim(rtrim(number_format($sale->tax_percent, 2), '0'), '.')]) : __('pos.ticket.tax', ['rate' => rtrim(rtrim(number_format($sale->tax_percent, 2), '0'), '.')]) }}</td><td class="r-end">{{ money((int) $sale->tax, $cur) }}</td></tr>@endif
    @if ($sale->tips > 0)<tr><td>{{ __('pos.ticket.tips') }}</td><td class="r-end">{{ money((int) $sale->tips, $cur) }}</td></tr>@endif
    <tr class="r-grand"><td>{{ __('pos.ticket.total') }}</td><td class="r-end">{{ money((int) $sale->total, $cur) }}</td></tr>
    @foreach ($sale->salePayments as $p)
        <tr><td>{{ $p->method === 'deposit' ? __('pos.pay.deposit_paid') : __('salon.payments.methods.'.$p->method) }}@if ($p->reference && $p->method !== 'gift_card') <span dir="ltr">· {{ $p->reference }}</span>@endif</td>
            <td class="r-end">{{ money((int) ($p->tendered ?? $p->amount), $cur) }}</td></tr>
    @endforeach
    @if ($sale->change_due > 0)<tr><td>{{ __('pos.pay.change') }}</td><td class="r-end">{{ money((int) $sale->change_due, $cur) }}</td></tr>@endif
    @if ($sale->refunded_total > 0)<tr><td>{{ __('pos.refund.refunded') }}</td><td class="r-end">−{{ money((int) $sale->refunded_total, $cur) }}</td></tr>@endif
</table>

@if (! in_array($sale->status->value, ['completed', 'draft'], true))
    <div class="r-stamp">{{ $sale->status->getLabel() }}</div>
@endif

<div class="r-qr">
    <img src="{{ $qr }}" alt="QR" width="110" height="110">
    <div class="r-muted">{{ $qrLabel }}</div>
</div>

<div class="r-foot">
    @if ($footer)<div>{{ $footer }}</div>@endif
    <div class="r-muted">{{ __('pos.receipt.thanks') }}</div>
</div>
