<x-filament-panels::page>
    <x-filament::tabs>
        @foreach (['cards' => 'heroicon-m-light-bulb', 'churn' => 'heroicon-m-user-minus', 'forecast' => 'heroicon-m-chart-bar', 'reorder' => 'heroicon-m-archive-box', 'pricing' => 'heroicon-m-tag', 'ask' => 'heroicon-m-chat-bubble-left-right'] as $key => $icon)
            <x-filament::tabs.item :active="$tab === $key" :icon="$icon" wire:click="setTab('{{ $key }}')">{{ __('insights.hub.tabs.'.$key) }}</x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    <div wire:loading.delay wire:target="setTab" class="grid gap-4 md:grid-cols-3">
        @for ($i = 0; $i < 3; $i++)<div class="h-40 animate-pulse rounded-2xl bg-gray-100 dark:bg-white/5"></div>@endfor
    </div>

    <div wire:loading.remove wire:target="setTab">
        @if ($tab === 'cards')
            @if ($this->cards->isEmpty())
                <x-filament::section>
                    <div class="py-10 text-center">
                        <x-filament::icon icon="heroicon-o-sparkles" class="mx-auto mb-3 h-9 w-9 text-primary-500" />
                        <p class="font-display text-lg text-gray-950 dark:text-white">{{ __('insights.hub.empty_title') }}</p>
                        <p class="mt-1 text-sm text-gray-500">{{ __('insights.hub.empty_body') }}</p>
                    </div>
                </x-filament::section>
            @else
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->cards as $card)
                        @include('filament.dashboard.insights.partials.card', ['card' => $card])
                    @endforeach
                </div>
            @endif

        @elseif ($tab === 'churn')
            <x-filament::section :heading="__('insights.churn.title')" :description="__('insights.churn.subtitle')">
                <x-slot name="afterHeader">
                    @if (count($this->churn) && tenant()->hasModule('marketing') && auth()->user()->can('marketing.manage'))
                        <x-filament::button size="sm" class="rounded-full" wire:click="winBackDraft">{{ __('insights.churn.draft') }}</x-filament::button>
                    @endif
                </x-slot>
                @if (empty($this->churn))
                    <p class="py-8 text-center text-sm text-gray-500">{{ __('insights.churn.empty') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10">
                                    <th class="py-2 text-start">{{ __('insights.reports.cols.clients') }}</th>
                                    <th class="py-2 text-end">{{ __('insights.reports.cols.cycle') }}</th>
                                    <th class="py-2 text-end">{{ __('insights.reports.cols.days_since') }}</th>
                                    <th class="py-2 text-end">{{ __('insights.reports.cols.overdue') }}</th>
                                    <th class="py-2 text-start ps-4">{{ __('insights.churn.message_col') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->churn as $row)
                                    <tr class="border-b border-gray-100 align-top last:border-0 dark:border-white/5" wire:key="churn-{{ $row['client_id'] }}">
                                        <td class="py-3">
                                            <span class="font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</span>
                                            <span class="block text-xs text-gray-500" dir="ltr">{{ $row['phone'] }}</span>
                                        </td>
                                        <td class="py-3 text-end tabular-nums">{{ trans_choice('insights.churn.days', $row['cycle_days'], ['count' => $row['cycle_days']]) }}</td>
                                        <td class="py-3 text-end tabular-nums">{{ $row['days_since'] }}</td>
                                        <td class="py-3 text-end"><x-filament::badge :color="$row['overdue_ratio'] >= 3 ? 'danger' : 'warning'">{{ $row['overdue_ratio'] }}×</x-filament::badge></td>
                                        <td class="py-3 ps-4 text-xs text-gray-600 dark:text-gray-300">
                                            <span x-data>{{ $row['message'] }}
                                                <button type="button" class="ms-1 text-primary-600 hover:underline" x-on:click="navigator.clipboard.writeText(@js($row['message'])); $tooltip(@js(__('salon.ui.copied')))">{{ __('salon.ui.copy') }}</button>
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>

        @elseif ($tab === 'forecast')
            @php($fc = $this->forecast)
            <x-filament::section :heading="__('insights.forecast.title')" :description="__('insights.forecast.subtitle')">
                @if ($fc['branches']->count() > 1)
                    <x-slot name="afterHeader">
                        <select wire:model.live="forecastBranchId" class="rounded-[10px] border border-gray-200 bg-white px-3 py-1.5 text-sm dark:border-white/10 dark:bg-white/5">
                            @foreach ($fc['branches'] as $b)
                                <option value="{{ $b->id }}" @selected($fc['branch']?->id === $b->id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </x-slot>
                @endif
                @if (empty($fc['days']))
                    <p class="py-8 text-center text-sm text-gray-500">{{ __('insights.reports.no_data') }}</p>
                @else
                    @php($max = max(1, ...array_map(fn ($d) => max($d['forecast'], $d['booked']), $fc['days'])))
                    <div class="grid grid-cols-7 gap-2">
                        @foreach ($fc['days'] as $day)
                            <div class="rounded-xl border border-gray-100 p-3 text-center dark:border-white/10" title="{{ $day['explanation'] }}" wire:key="fc-{{ $day['date'] }}">
                                <p class="text-[11px] uppercase tracking-wide text-gray-500">{{ \Carbon\CarbonImmutable::parse($day['date'])->translatedFormat('D j') }}</p>
                                <div class="mx-auto mt-2 flex h-20 w-6 items-end overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                    <div class="w-full rounded-full" style="height: {{ round($day['forecast'] / $max * 100) }}%; background: #C8A96A"></div>
                                </div>
                                <p class="mt-2 font-semibold text-gray-950 dark:text-white">{{ $day['forecast'] }}</p>
                                <p class="text-[11px] text-gray-500">{{ __('insights.forecast.booked', ['count' => $day['booked']]) }}</p>
                                <p class="text-[11px] text-gray-500">{{ trans_choice('insights.forecast.staff', $day['staff_needed'], ['count' => $day['staff_needed']]) }}</p>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-4 text-xs text-gray-500">{{ __('insights.forecast.method') }}</p>
                @endif
            </x-filament::section>

        @elseif ($tab === 'reorder')
            @forelse ($this->reorder as $branchId => $rows)
                <x-filament::section :heading="$rows->first()['branch']" class="mb-4" wire:key="ro-{{ $branchId }}">
                    <x-slot name="afterHeader">
                        @can('inventory.purchase')
                            <x-filament::button size="sm" class="rounded-full" wire:click="reorderDraft({{ $branchId }})">{{ __('insights.reorder.draft') }}</x-filament::button>
                        @endcan
                    </x-slot>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10">
                                <th class="py-2 text-start">{{ __('insights.reports.cols.variant') }}</th>
                                <th class="py-2 text-end">{{ __('insights.reports.cols.on_hand') }}</th>
                                <th class="py-2 text-end">{{ __('insights.reports.cols.daily_usage') }}</th>
                                <th class="py-2 text-end">{{ __('insights.reports.cols.days_cover') }}</th>
                                <th class="py-2 text-end">{{ __('insights.reports.cols.suggested') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                    <td class="py-2.5 font-medium text-gray-950 dark:text-white">{{ $r['item'] }}</td>
                                    <td class="py-2.5 text-end tabular-nums">{{ $r['on_hand'] }}</td>
                                    <td class="py-2.5 text-end tabular-nums">{{ $r['daily_usage'] }}</td>
                                    <td class="py-2.5 text-end tabular-nums">{{ $r['days_cover'] ?? '—' }}</td>
                                    <td class="py-2.5 text-end font-semibold tabular-nums">{{ $r['suggested_qty'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-filament::section>
            @empty
                <x-filament::section><p class="py-8 text-center text-sm text-gray-500">{{ __('insights.reorder.empty') }}</p></x-filament::section>
            @endforelse
            <p class="text-xs text-gray-500">{{ __('insights.reorder.method') }}</p>

        @elseif ($tab === 'pricing')
            <x-filament::section :heading="__('insights.pricing.title')" :description="__('insights.pricing.subtitle')">
                @if (empty($this->pricing))
                    <p class="py-8 text-center text-sm text-gray-500">{{ __('insights.pricing.empty') }}</p>
                @else
                    <ul class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($this->pricing as $i => $hint)
                            <li class="flex flex-wrap items-center gap-3 py-3" wire:key="ph-{{ $i }}">
                                <x-filament::badge :color="$hint['kind'] === 'peak' ? 'warning' : 'info'">{{ __('insights.pricing.'.$hint['kind']) }}</x-filament::badge>
                                <span class="font-medium text-gray-950 dark:text-white">{{ \App\Filament\Dashboard\Pages\Reports\PerformanceReport::weekdayName($hint['weekday']) }} {{ sprintf('%02d:00–%02d:00', $hint['from'], $hint['to']) }}</span>
                                <span class="text-sm text-gray-500">{{ __('insights.pricing.util', ['pct' => $hint['utilization']]) }}</span>
                                <span class="ms-auto text-sm font-semibold {{ $hint['change_pct'] > 0 ? 'text-warning-600' : 'text-info-600' }}">{{ $hint['change_pct'] > 0 ? '+' : '' }}{{ $hint['change_pct'] }}%</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3 text-xs text-gray-500">{{ __('insights.pricing.note') }}</p>
                @endif
            </x-filament::section>

        @elseif ($tab === 'ask')
            <x-filament::section :heading="__('insights.ask.title')" :description="__('insights.ask.subtitle')">
                <form wire:submit="ask" class="flex flex-col gap-3 sm:flex-row">
                    <input type="text" wire:model="question" maxlength="300" placeholder="{{ __('insights.ask.placeholder') }}"
                           class="min-h-11 flex-1 rounded-[10px] border border-gray-200 bg-white px-4 py-2 text-sm dark:border-white/10 dark:bg-white/5">
                    <x-filament::button type="submit" class="rounded-full" wire:loading.attr="disabled" wire:target="ask">{{ __('insights.ask.button') }}</x-filament::button>
                </form>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach (__('insights.ask.examples') as $example)
                        <button type="button" wire:click="useExample(@js($example))" class="rounded-full border border-gray-200 px-3 py-1 text-xs text-gray-600 transition hover:border-primary-400 hover:text-primary-700 dark:border-white/10 dark:text-gray-300">{{ $example }}</button>
                    @endforeach
                </div>

                <div wire:loading.delay wire:target="ask,useExample" class="mt-6 h-24 animate-pulse rounded-2xl bg-gray-100 dark:bg-white/5"></div>
                @if ($answer)
                    <div wire:loading.remove wire:target="ask,useExample" class="mt-6 rounded-2xl border p-5 {{ $answer['ok'] ? 'border-primary-200 bg-primary-50/40 dark:border-primary-500/30 dark:bg-white/5' : 'border-danger-200 bg-danger-50/40 dark:border-danger-500/30' }}">
                        <p class="font-display text-lg text-gray-950 dark:text-white">{{ $answer['answer'] }}</p>
                        @if ($answer['rows'])
                            <p class="mt-4 text-xs uppercase tracking-wide text-gray-500">{{ __('insights.ask.numbers_used') }}</p>
                            <dl class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach ($answer['rows'] as [$label, $value])
                                    <div class="flex justify-between rounded-lg bg-white px-3 py-2 text-sm dark:bg-white/5"><dt class="text-gray-500">{{ $label }}</dt><dd class="font-semibold tabular-nums">{{ $value }}</dd></div>
                                @endforeach
                            </dl>
                        @endif
                        @if ($answer['plan'])
                            <details class="mt-3 text-xs text-gray-500">
                                <summary class="cursor-pointer">{{ __('insights.ask.query_used', ['source' => $answer['source'] === 'ai' ? 'AI' : __('insights.cards.rules')]) }}</summary>
                                <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-50 p-3 dark:bg-white/5" dir="ltr">{{ json_encode($answer['plan'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </details>
                        @endif
                    </div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
