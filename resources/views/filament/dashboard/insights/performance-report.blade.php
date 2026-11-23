@php($d = $this->data)
@php($sections = $this->formattedSections('pdf'))
<x-filament-panels::page>
    @include('filament.dashboard.insights.partials.filters')

    <div wire:loading.delay class="grid gap-4 md:grid-cols-4">
        @for ($i = 0; $i < 4; $i++)<div class="h-24 animate-pulse rounded-2xl bg-gray-100 dark:bg-white/5"></div>@endfor
    </div>

    <div wire:loading.remove class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @include('filament.dashboard.insights.partials.kpi', ['label' => __('insights.reports.performance.utilization'), 'value' => $d['utilization']['rate'].'%', 'hint' => __('insights.reports.performance.utilization_hint', ['booked' => number_format(intdiv($d['utilization']['booked_minutes'], 60)), 'available' => number_format(intdiv($d['utilization']['available_minutes'], 60))])])
            @include('filament.dashboard.insights.partials.kpi', ['label' => __('insights.reports.performance.no_show_rate'), 'value' => $d['no_show']['rate'].'%', 'hint' => trans_choice('insights.reports.performance.no_shows', $d['no_show']['no_shows'], ['count' => $d['no_show']['no_shows']]), 'tone' => $d['no_show']['rate'] > 8 ? 'down' : null])
            @include('filament.dashboard.insights.partials.kpi', ['label' => __('insights.reports.performance.rebook_rate'), 'value' => $d['rebook']['rate'].'%', 'hint' => __('insights.reports.performance.rebook_hint', ['days' => $d['rebook']['window_days']])])
            @include('filament.dashboard.insights.partials.kpi', ['label' => __('insights.reports.performance.new_clients'), 'value' => number_format($d['new_clients']), 'hint' => trans_choice('insights.reports.performance.bookings_count', $d['bookings'], ['count' => $d['bookings']])])
        </div>

        <x-filament::section :heading="__('insights.reports.performance.heatmap')" :description="__('insights.reports.performance.heatmap_hint')" compact>
            <div class="overflow-x-auto">
                <table class="w-full border-separate border-spacing-1 text-center text-[11px]" dir="ltr">
                    <thead>
                        <tr>
                            <th></th>
                            @foreach ($d['heatmap']['hours'] as $h)
                                <th class="font-normal text-gray-500">{{ sprintf('%02d', $h) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([6, 0, 1, 2, 3, 4, 5] as $wd)
                            <tr wire:key="hm-{{ $wd }}">
                                <th class="pe-2 text-end font-medium text-gray-600 dark:text-gray-300">{{ \App\Filament\Dashboard\Pages\Reports\PerformanceReport::weekdayName($wd) }}</th>
                                @foreach ($d['heatmap']['hours'] as $h)
                                    @php($v = $d['heatmap']['matrix'][$wd][$h])
                                    <td class="h-8 min-w-8 rounded-md {{ $v === null ? 'bg-gray-50 dark:bg-white/5' : '' }}"
                                        @if ($v !== null) style="background: rgba(200,169,106,{{ max(0.06, round($v / 100, 2)) }}); color: {{ $v >= 60 ? '#1C1A17' : '#7a736a' }}" @endif
                                        title="{{ \App\Filament\Dashboard\Pages\Reports\PerformanceReport::weekdayName($wd) }} {{ sprintf('%02d:00', $h) }} · {{ $v === null ? __('insights.reports.performance.closed') : $v.'%' }}">
                                        {{ $v === null ? '' : round($v) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @include('filament.dashboard.insights.partials.table', ['section' => $sections[2]])
    </div>
</x-filament-panels::page>
