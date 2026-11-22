{{-- $section: formatted section (title, headings, rows) --}}
<x-filament::section :heading="$section['title']" compact>
    @if (empty($section['rows']))
        <div class="py-8 text-center text-sm text-gray-500">
            <x-filament::icon icon="heroicon-o-chart-bar" class="mx-auto mb-2 h-7 w-7 text-primary-500" />
            {{ $empty ?? __('insights.reports.no_data') }}
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10">
                        @foreach ($section['headings'] as $i => $heading)
                            <th @class(['py-2 font-medium', 'text-start' => $i === 0, 'text-end' => $i > 0])>{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['rows'] as $r => $row)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-white/5" wire:key="{{ md5($section['title']) }}-{{ $r }}">
                            @foreach (array_values($row) as $i => $cell)
                                <td @class(['py-2.5', 'text-start font-medium text-gray-950 dark:text-white' => $i === 0, 'text-end tabular-nums' => $i > 0])>{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
