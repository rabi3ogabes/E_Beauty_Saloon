@php($d = $this->data)
<x-filament-panels::page>
    @include('filament.dashboard.insights.partials.filters')

    <div wire:loading.delay class="grid gap-4 md:grid-cols-4">
        @for ($i = 0; $i < 4; $i++)<div class="h-24 animate-pulse rounded-2xl bg-gray-100 dark:bg-white/5"></div>@endfor
    </div>

    <div wire:loading.remove class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @include('filament.dashboard.insights.partials.kpi', [
                'label' => __('insights.reports.revenue.total'), 'value' => money($d['total']),
                'hint' => $d['change'] === null ? __('insights.reports.no_previous') : __('insights.reports.vs_previous', ['pct' => ($d['change'] > 0 ? '+' : '').$d['change']]),
                'tone' => $d['change'] === null ? null : ($d['change'] >= 0 ? 'up' : 'down'),
            ])
            @include('filament.dashboard.insights.partials.kpi', ['label' => __('insights.reports.cols.average_ticket'), 'value' => money($d['ticket']['average']), 'hint' => trans_choice('insights.reports.tickets_count', $d['ticket']['tickets'], ['count' => $d['ticket']['tickets']])])
            @include('filament.dashboard.insights.partials.kpi', ['label' => __('insights.reports.revenue.ltv'), 'value' => money($d['ltv']['average']), 'hint' => __('insights.reports.revenue.ltv_hint', ['visits' => $d['ltv']['average_visits'], 'clients' => $d['ltv']['clients']])])
            @include('filament.dashboard.insights.partials.kpi', ['label' => __('insights.reports.revenue.per_visit'), 'value' => money($d['ltv']['value_per_visit']), 'hint' => __('insights.reports.revenue.per_visit_hint')])
        </div>

        <x-filament::section :heading="__('insights.reports.revenue.daily')" compact>
            @include('filament.dashboard.insights.partials.bars', ['series' => $d['daily'], 'format' => fn ($v) => money($v), 'label' => __('insights.reports.revenue.daily')])
        </x-filament::section>

        <div class="grid gap-6 xl:grid-cols-2">
            @foreach ($this->formattedSections('pdf') as $i => $section)
                @continue($i === 4)
                @include('filament.dashboard.insights.partials.table', ['section' => $section])
            @endforeach
        </div>

        <p class="text-xs text-gray-500">
            {{ __('insights.reports.revenue.definition') }}
            @if (\App\Filament\Dashboard\Pages\CommissionReport::canAccess())
                · <a class="text-primary-600 underline-offset-2 hover:underline" href="{{ \App\Filament\Dashboard\Pages\CommissionReport::getUrl() }}">{{ __('insights.reports.revenue.commissions_link') }}</a>
            @endif
        </p>
    </div>
</x-filament-panels::page>
