<style>
    body { font-family: dejavusans; color: #1c1a17; font-size: 10pt; }
    .muted { color: #7a736a; }
    .gold { color: #a88b4f; }
    h1 { font-size: 20pt; font-weight: normal; margin: 0; letter-spacing: 1px; }
    table { width: 100%; border-collapse: collapse; }
    .label { color: #a88b4f; font-size: 8pt; text-transform: uppercase; margin: 0 0 4px; }
    .items th { text-align: {{ $rtl ? 'right' : 'left' }}; font-size: 8pt; text-transform: uppercase; color: #7a736a; border-bottom: 1px solid #ece6dd; padding: 8px 4px; }
    .items td { padding: 9px 4px; border-bottom: 1px solid #f3eee6; vertical-align: top; }
    .num { text-align: {{ $rtl ? 'left' : 'right' }}; }
    .box { border: 1px solid #ece6dd; border-radius: 8px; padding: 10px 12px; }
    .check { width: 14px; height: 14px; border: 1px solid #7a736a; display: inline-block; }
    .total-row td { font-size: 13pt; padding-top: 10px; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 10px; background: #f3ead8; color: #a88b4f; font-size: 8pt; }
</style>
