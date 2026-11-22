<html dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<style>
    body { font-family: dejavusans; color: #1c1a17; font-size: 8pt; }
    .r-head { text-align: center; padding-bottom: 6px; border-bottom: 1px dashed #bbb; margin-bottom: 6px; }
    .r-logo { height: 28px; }
    .r-salon { font-size: 13pt; }
    .r-muted { color: #7a736a; font-size: 7pt; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 2px 0; vertical-align: top; }
    .r-end { text-align: {{ $rtl ? 'left' : 'right' }}; white-space: nowrap; }
    .r-items { margin: 6px 0; border-top: 1px dashed #bbb; border-bottom: 1px dashed #bbb; }
    .r-grand td { font-size: 11pt; font-weight: bold; border-top: 1px solid #1c1a17; padding-top: 4px; }
    .r-stamp { text-align: center; border: 1.5px solid #a4473f; color: #a4473f; padding: 3px; margin: 6px 0; font-weight: bold; }
    .r-qr { text-align: center; margin-top: 8px; }
    .r-foot { text-align: center; margin-top: 6px; }
</style>
</head>
<body>
@include('pos.partials.receipt-body')
</body>
</html>
