@php($dir = $rtl ? 'rtl' : 'ltr')
@php($start = $rtl ? 'right' : 'left')
@php($end = $rtl ? 'left' : 'right')
<html dir="{{ $dir }}">
<head>
<style>
    body { font-family: dejavusans; color: #1c1a17; font-size: 9.5pt; }
    h1 { font-size: 19pt; font-weight: normal; margin: 0; }
    h2 { font-size: 12pt; font-weight: normal; color: #a88b4f; margin: 18px 0 6px; }
    .muted { color: #7a736a; }
    table { width: 100%; border-collapse: collapse; }
    .grid td { padding: 6px 4px; border-bottom: 1px solid #f0ebe3; text-align: {{ $start }}; }
    .grid th { text-align: {{ $start }}; font-size: 7.5pt; text-transform: uppercase; color: #7a736a; border-bottom: 1px solid #ece6dd; padding: 5px 4px; }
    .grid td.num, .grid th.num { text-align: {{ $end }}; }
    .gold-rule { height: 2px; background: #c8a96a; margin: 10px 0 4px; }
</style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="muted">{{ $tenant?->name }} · {{ $period }}@if (! empty($branch)) · {{ $branch }}@endif · {{ now($tenant?->timezone ?? 'UTC')->format('Y-m-d H:i') }}</p>
    <div class="gold-rule"></div>

    @foreach ($sections as $section)
        <h2>{{ $section['title'] }}</h2>
        @if (empty($section['rows']))
            <p class="muted">{{ __('insights.reports.no_data') }}</p>
        @else
            <table class="grid">
                <thead>
                    <tr>
                        @foreach ($section['headings'] as $i => $heading)
                            <th class="{{ $i > 0 ? 'num' : '' }}">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['rows'] as $row)
                        <tr>
                            @foreach (array_values($row) as $i => $cell)
                                <td class="{{ $i > 0 ? 'num' : '' }}">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
</body>
</html>
