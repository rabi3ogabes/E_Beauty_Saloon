@php
    $locale = app()->getLocale();
    $tz = $order->branch?->timezone ?: 'Asia/Qatar';
    $a = $order->address ?? [];
    $cur = $order->currency;
@endphp
<html dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>@include('pdf.shop._style')</head>
<body>
<table>
    <tr>
        <td style="width: 60%">
            <h1>{{ $tenant->name }}</h1>
            <p class="muted" style="margin: 2px 0">{{ $tenant->legal_name }}</p>
            <p class="muted" style="margin: 2px 0">{{ $order->branch?->getTranslation('name', $locale) }} · {{ $order->branch?->phone }}</p>
        </td>
        <td class="num" style="width: 40%">
            <p style="font-size: 14pt; margin: 0">{{ __('shop.pdf.invoice') }}</p>
            <p style="margin: 2px 0">{{ $order->number }}</p>
            <p class="muted" style="margin: 2px 0">{{ __('shop.pdf.order_date') }}: {{ $order->created_at->setTimezone($tz)->format('Y-m-d H:i') }}</p>
            <span class="badge">{{ __('shop.payment_status.'.$order->paymentStatus()) }}</span>
        </td>
    </tr>
</table>

<table style="margin-top: 22px">
    <tr>
        <td style="width: 50%; vertical-align: top">
            <p class="label">{{ __('shop.pdf.bill_to') }}</p>
            <p style="margin: 0; font-size: 12pt">{{ $order->customer_name }}</p>
            <p class="muted" style="margin: 0" dir="ltr">{{ $order->customer_phone }}</p>
            @if ($order->customer_email)<p class="muted" style="margin: 0">{{ $order->customer_email }}</p>@endif
        </td>
        <td style="width: 50%; vertical-align: top">
            <p class="label">{{ $order->isPickup() ? __('shop.pdf.pickup_at') : __('shop.pdf.ship_to') }}</p>
            @if ($order->isPickup())
                <p style="margin: 0">{{ $order->branch?->getTranslation('name', $locale) }}</p>
            @else
                <p style="margin: 0">{{ collect([$a['unit'] ?? null, $a['building'] ?? null, $a['street'] ?? null, $a['area'] ?? null])->filter()->implode(', ') }}</p>
                <p class="muted" style="margin: 0">{{ $order->zone?->getTranslation('name', $locale) }}</p>
            @endif
            <p class="muted" style="margin: 0">{{ __('shop.pdf.fulfilment') }}: {{ __('shop.fulfilment.'.$order->fulfilment) }}</p>
        </td>
    </tr>
</table>

<table class="items" style="margin-top: 20px">
    <thead><tr><th>{{ __('shop.pdf.item') }}</th><th>{{ __('shop.pdf.sku') }}</th><th class="num">{{ __('shop.pdf.qty') }}</th><th class="num">{{ __('shop.pdf.unit') }}</th><th class="num">{{ __('shop.pdf.amount') }}</th></tr></thead>
    <tbody>
        @foreach ($order->items as $item)
            <tr>
                <td>{{ $item->label($locale) }}</td>
                <td class="muted">{{ $item->sku }}</td>
                <td class="num">{{ $item->qty }}</td>
                <td class="num">{{ money($item->unit_price, $cur) }}</td>
                <td class="num">{{ money($item->total, $cur) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table style="margin-top: 14px">
    <tr><td style="width: 55%"></td><td style="width: 45%">
        <table>
            <tr><td class="muted">{{ __('shop.pdf.subtotal') }}</td><td class="num">{{ money($order->subtotal, $cur) }}</td></tr>
            @if ($order->discount)<tr><td class="muted">{{ __('shop.pdf.discount') }} {{ $order->coupon_code }}</td><td class="num">−{{ money($order->discount, $cur) }}</td></tr>@endif
            @if (! $order->isPickup())<tr><td class="muted">{{ __('shop.pdf.delivery') }}</td><td class="num">{{ money($order->delivery_fee, $cur) }}</td></tr>@endif
            @if ($order->gift_wrap_fee)<tr><td class="muted">{{ __('shop.pdf.gift_wrap') }}</td><td class="num">{{ money($order->gift_wrap_fee, $cur) }}</td></tr>@endif
            @if ($order->tax)<tr><td class="muted">{{ $order->tax_inclusive ? __('shop.pdf.tax_included') : __('shop.pdf.tax') }}</td><td class="num">{{ money($order->tax, $cur) }}</td></tr>@endif
            <tr class="total-row"><td>{{ __('shop.pdf.total') }}</td><td class="num">{{ money($order->total, $cur) }}</td></tr>
            <tr><td class="muted">{{ __('shop.pdf.paid') }}</td><td class="num">{{ money($order->paid, $cur) }}</td></tr>
            @if ($order->balanceDue())<tr><td>{{ __('shop.pdf.balance') }}</td><td class="num">{{ money($order->balanceDue(), $cur) }}</td></tr>@endif
        </table>
    </td></tr>
</table>

<p class="muted" style="margin-top: 14px">{{ __('shop.pdf.payment') }}:
    @foreach ($order->payments->where('purpose', 'payment')->whereIn('status', ['succeeded', 'partially_refunded', 'refunded']) as $p)
        {{ __('shop.payment_methods.'.$p->method) }} {{ money($p->amount, $cur) }}@if (! $loop->last) · @endif
    @endforeach
</p>
<p class="gold" style="margin-top: 28px; text-align: center">{{ $tenant->brand('receipt_footer') ?: __('shop.pdf.thank_you') }}</p>
</body>
</html>
