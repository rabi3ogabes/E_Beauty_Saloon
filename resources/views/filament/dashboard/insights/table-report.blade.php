@php($d = $this->data)
<x-filament-panels::page>
    @include('filament.dashboard.insights.partials.filters', ['withBranch' => method_exists($this, 'hasBranchFilter') ? $this->hasBranchFilter() : true])

    <div wire:loading.delay class="space-y-2">
        @for ($i = 0; $i < 5; $i++)<div class="h-10 animate-pulse rounded-lg bg-gray-100 dark:bg-white/5"></div>@endfor
    </div>

    <div wire:loading.remove class="space-y-6">
        @if (! empty($d['kpis']))
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($d['kpis'] as [$label, $value])
                    @include('filament.dashboard.insights.partials.kpi', ['label' => $label, 'value' => $value])
                @endforeach
            </div>
        @endif

        @foreach ($this->formattedSections('pdf') as $section)
            @include('filament.dashboard.insights.partials.table', ['section' => $section, 'empty' => $d['empty'] ?? null])
        @endforeach
    </div>
</x-filament-panels::page>
