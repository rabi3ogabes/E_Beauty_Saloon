<x-filament-widgets::widget>
    <x-filament::section :heading="__('insights.pulse.title')" :description="__('insights.pulse.subtitle')" icon="heroicon-o-building-storefront">
        @if (empty($rows))
            <div class="py-8 text-center text-sm text-gray-500">{{ __('insights.pulse.empty') }}</div>
        @else
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($rows as $row)
                    <div wire:key="pulse-{{ $row['branch_id'] }}" class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm transition duration-200 ease-out hover:shadow-md dark:border-white/10 dark:bg-white/5">
                        <div class="flex items-baseline justify-between gap-2">
                            <h3 class="font-display text-lg font-semibold text-gray-950 dark:text-white">{{ $row['branch'] }}</h3>
                            <span class="text-xs text-gray-500">{{ trans_choice('insights.pulse.staff', $row['staff_on_shift'], ['count' => $row['staff_on_shift']]) }}</span>
                        </div>

                        <dl class="mt-4 grid grid-cols-3 gap-3 text-center">
                            <div class="rounded-xl bg-gray-50 px-2 py-3 dark:bg-white/5">
                                <dt class="text-[11px] uppercase tracking-wide text-gray-500">{{ __('insights.kpi.bookings') }}</dt>
                                <dd class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $row['bookings'] }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 px-2 py-3 dark:bg-white/5">
                                <dt class="text-[11px] uppercase tracking-wide text-gray-500">{{ __('insights.kpi.low_stock') }}</dt>
                                <dd @class(['mt-1 text-xl font-semibold', 'text-danger-600' => $row['low_stock'] > 0, 'text-gray-950 dark:text-white' => $row['low_stock'] === 0])>{{ $row['low_stock'] }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 px-2 py-3 dark:bg-white/5">
                                <dt class="text-[11px] uppercase tracking-wide text-gray-500">{{ $showOrders ? __('insights.kpi.pending_orders') : __('insights.kpi.staff_on_shift') }}</dt>
                                <dd class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $showOrders ? $row['pending_orders'] : $row['staff_on_shift'] }}</dd>
                            </div>
                        </dl>

                        <div class="mt-4 space-y-3">
                            <div>
                                <div class="flex justify-between text-xs text-gray-500">
                                    <span>{{ __('insights.kpi.utilization') }}</span>
                                    <span class="font-medium text-gray-700 dark:text-gray-200">{{ $row['utilization'] }}%</span>
                                </div>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                    <div class="h-full rounded-full" style="width: {{ min(100, $row['utilization']) }}%; background: #C8A96A"></div>
                                </div>
                            </div>
                            @if ($showRevenue)
                                <div>
                                    <div class="flex justify-between text-xs text-gray-500">
                                        <span>{{ __('insights.kpi.revenue') }} · {{ money($row['revenue']) }}</span>
                                        <span class="font-medium text-gray-700 dark:text-gray-200">
                                            @if ($row['target'] > 0)
                                                {{ $row['target_progress'] }}% {{ __('insights.pulse.of') }} {{ money($row['target']) }}@if ($row['target_auto'])<sup title="{{ __('insights.pulse.auto_target') }}">*</sup>@endif
                                            @else
                                                {{ __('insights.kpi.no_target') }}
                                            @endif
                                        </span>
                                    </div>
                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                        <div class="h-full rounded-full" style="width: {{ min(100, (float) ($row['target_progress'] ?? 0)) }}%; background: {{ ($row['target_progress'] ?? 0) >= 100 ? '#4F7A5A' : '#A88B4F' }}"></div>
                                    </div>
                                </div>
                            @endif
                        </div>

                        @if ($row['staff_names'])
                            <p class="mt-4 truncate text-xs text-gray-500" title="{{ implode(', ', $row['staff_names']) }}">
                                <x-filament::icon icon="heroicon-m-user-group" class="inline h-3.5 w-3.5 align-text-bottom text-primary-500" />
                                {{ implode(' · ', $row['staff_names']) }}
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
