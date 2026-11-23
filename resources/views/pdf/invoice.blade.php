@php($dir = $rtl ? 'rtl' : 'ltr')
<html dir="{{ $dir }}">
<head>
<style>
    body { font-family: dejavusans; color: #1c1a17; font-size: 10pt; }
    .muted { color: #7a736a; }
    .gold { color: #a88b4f; }
    h1 { font-size: 22pt; font-weight: normal; margin: 0; letter-spacing: 1px; }
    table { width: 100%; border-collapse: collapse; }
    .items th { text-align: {{ $rtl ? 'right' : 'left' }}; font-size: 8pt; text-transform: uppercase; color: #7a736a; border-bottom: 1px solid #ece6dd; padding: 8px 4px; }
    .items td { padding: 9px 4px; border-bottom: 1px solid #f3eee6; }
    .num { text-align: {{ $rtl ? 'left' : 'right' }}; }
    .total-row td { font-size: 13pt; padding-top: 12px; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 10px; background: #f3ead8; color: #a88b4f; font-size: 8pt; }
    .paid { background: #e3efe6; color: #4f7a5a; }
</style>
</head>
<body>
<table>
    <tr>
        <td style="width: 60%">
            <h1>{{ config('salon.name') }}</h1>
            <p class="muted">{{ __('admin.invoice.tax_invoice') }}</p>
        </td>
        <td class="num" style="width: 40%">
            <p style="font-size: 14pt; margin: 0">{{ $invoice->number }}</p>
            <p class="muted" style="margin: 2px 0">{{ __('fields.issued_at') }}: {{ $invoice->issued_at->format('Y-m-d') }}</p>
            @if ($invoice->due_at)<p class="muted" style="margin: 2px 0">{{ __('fields.due_at') }}: {{ $invoice->due_at->format('Y-m-d') }}</p>@endif
            <span class="badge {{ $invoice->status->value === 'paid' ? 'paid' : '' }}">{{ $invoice->status->getLabel() }}</span>
        </td>
    </tr>
</table>

<table style="margin-top: 26px">
    <tr>
        <td style="width: 60%; vertical-align: top">
            <p class="gold" style="font-size: 8pt; text-transform: uppercase; margin-bottom: 4px">{{ __('admin.invoice.billed_to') }}</p>
            <p style="margin: 0; font-size: 12pt">{{ $invoice->billing_details['name'] ?? $invoice->tenant->name }}</p>
            @if (! empty($invoice->billing_details['email']))<p class="muted" style="margin: 0">{{ $invoice->billing_details['email'] }}</p>@endif
            @if (! empty($invoice->billing_details['tax_number']))<p class="muted" style="margin: 0">{{ __('admin.tenant.tax_number') }}: {{ $invoice->billing_details['tax_number'] }}</p>@endif
        </td>
        <td class="num" style="width: 40%">
            <img src="{{ $qr }}" style="width: 90px; height: 90px">
        </td>
    </tr>
</table>

<table class="items" style="margin-top: 22px">
    <thead><tr><th>{{ __('fields.description') }}</th><th class="num">{{ __('admin.invoice.qty') }}</th><th class="num">{{ __('admin.invoice.unit') }}</th><th class="num">{{ __('fields.amount') }}</th></tr></thead>
    <tbody>
        @foreach ($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="num">{{ $item->quantity }}</td>
                <td class="num">{{ money($item->unit_amount, $invoice->currency) }}</td>
                <td class="num">{{ money($item->amount, $invoice->currency) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table style="margin-top: 14px">
    <tr><td style="width: 55%"></td><td>
        <table>
            <tr><td class="muted">{{ __('salon.billing.subtotal') }}</td><td class="num">{{ money($invoice->subtotal, $invoice->currency) }}</td></tr>
            @if ($invoice->discount_total)<tr><td class="muted">{{ __('salon.billing.discount') }}</td><td class="num">− {{ money($invoice->discount_total, $invoice->currency) }}</td></tr>@endif
            <tr><td class="muted">{{ __('salon.billing.tax') }} ({{ rtrim(rtrim(number_format($invoice->tax_percent, 2), '0'), '.') }}%)</td><td class="num">{{ money($invoice->tax_total, $invoice->currency) }}</td></tr>
            <tr class="total-row"><td>{{ __('salon.billing.total') }}</td><td class="num">{{ money($invoice->total, $invoice->currency) }}</td></tr>
            @if ($invoice->amount_paid)<tr><td class="muted">{{ __('admin.invoice.paid') }}</td><td class="num">{{ money($invoice->amount_paid, $invoice->currency) }}</td></tr>@endif
        </table>
    </td></tr>
</table>

<p class="muted" style="margin-top: 40px; font-size: 8pt; text-align: center">{{ __('admin.invoice.footer', ['platform' => config('salon.name')]) }}</p>
</body>
</html>
