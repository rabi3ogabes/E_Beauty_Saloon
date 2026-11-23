@php
    $locale = app()->getLocale();
    $tz = $order->branch?->timezone ?: 'Asia/Qatar';
    $a = $order->address ?? [];
@endphp
<html dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>@include('pdf.shop._style')</head>
<body>
<table>
    <tr>
        <td style="width: 60%">
            <h1>{{ __('shop.pdf.packing_slip') }}</h1>
            <p class="muted" style="margin: 2px 0">{{ $tenant->name }} · {{ $order->branch?->getTranslation('name', $locale) }}</p>
        </td>
        <td class="num" style="width: 40%">
            <p style="font-size: 18pt; margin: 0">{{ $order->number }}</p>
            <p class="muted" style="margin: 2px 0">{{ $order->created_at->setTimezone($tz)->format('Y-m-d H:i') }}</p>
            <span class="badge">{{ __('shop.fulfilment.'.$order->fulfilment) }}</span>
        </td>
    </tr>
</table>

<table style="margin-top: 18px">
    <tr>
        <td class="box" style="width: 50%; vertical-align: top">
            <p class="label">{{ $order->isPickup() ? __('shop.pdf.pickup_at') : __('shop.pdf.ship_to') }}</p>
            <p style="margin: 0; font-size: 12pt">{{ $order->customer_name }}</p>
            <p style="margin: 0" dir="ltr">{{ $order->customer_phone }}</p>
            @if ($order->isPickup())
                <p class="muted" style="margin: 0">{{ $order->branch?->getTranslation('name', $locale) }}</p>
            @else
                <p style="margin: 0">{{ collect([$a['unit'] ?? null, $a['building'] ?? null, $a['street'] ?? null, $a['area'] ?? null])->filter()->implode(', ') }}</p>
                @if (! empty($a['notes']))<p class="muted" style="margin: 0">{{ $a['notes'] }}</p>@endif
                <p class="muted" style="margin: 0">{{ $order->zone?->getTranslation('name', $locale) }}@if ($order->delivery_slot) · {{ __('shop.pdf.slot') }} {{ $order->delivery_slot }}@endif</p>
            @endif
        </td>
        <td style="width: 4%"></td>
        <td class="box" style="width: 46%; vertical-align: top">
            <p class="label">{{ __('shop.pdf.payment') }}</p>
            <p style="margin: 0">{{ __('shop.payment_methods.'.$order->payment_method) }} — {{ __('shop.payment_status.'.$order->paymentStatus()) }}</p>
            @if ($order->balanceDue() > 0)<p style="margin: 4px 0 0; font-size: 13pt">{{ __('shop.pdf.balance') }}: {{ money($order->balanceDue(), $order->currency) }}</p>@endif
            @if ($order->driver)<p class="muted" style="margin: 4px 0 0">{{ $order->driver->name }} {{ $order->driver->phone }}</p>@endif
        </td>
    </tr>
</table>

<table class="items" style="margin-top: 20px">
    <thead><tr><th style="width: 6%">{{ __('shop.pdf.check') }}</th><th>{{ __('shop.pdf.item') }}</th><th>{{ __('shop.pdf.sku') }}</th><th class="num">{{ __('shop.pdf.qty') }}</th></tr></thead>
    <tbody>
        @foreach ($order->items as $item)
            <tr>
                <td><span class="check"></span></td>
                <td>{{ $item->label($locale) }}</td>
                <td class="muted">{{ $item->sku }}</td>
                <td class="num" style="font-size: 13pt">{{ $item->qty }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@if ($order->gift_wrap || $order->gift_message)
    <div class="box" style="margin-top: 18px">
        <p class="label">✦ {{ __('shop.pdf.gift_wrap') }}</p>
        @if ($order->gift_message)<p style="margin: 0; font-style: italic">“{{ $order->gift_message }}”</p>@endif
    </div>
@endif
@if ($order->notes)
    <p class="muted" style="margin-top: 12px">{{ $order->notes }}</p>
@endif

<table style="margin-top: 40px">
    <tr>
        <td class="muted">{{ __('shop.pdf.packed_by') }}: ____________________</td>
        <td class="muted num">{{ __('shop.pdf.checked_by') }}: ____________________</td>
    </tr>
</table>
</body>
</html>
