<x-filament-panels::page>
    @php($rows = $this->getRows())
    <div class="flex gap-2">
        @foreach (['30' => __('ai_studio.admin.last_30'), 'all' => __('ai_studio.admin.all_time')] as $value => $label)
            <x-filament::button size="sm" :color="$period === (string) $value ? 'primary' : 'gray'" wire:click="$set('period', '{{ $value }}')">{{ $label }}</x-filament::button>
        @endforeach
    </div>

    <x-filament::section>
        @if (empty($rows))
            <p class="text-sm text-gray-500">{{ __('ai_studio.admin.empty_margin') }}</p>
        @else
            <div class="relative overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-start text-xs uppercase tracking-wide text-gray-500 dark:border-white/10">
                            <th class="py-2 text-start">{{ __('ai_studio.admin.provider') }}</th>
                            <th class="py-2 text-end">{{ __('ai_studio.admin.jobs_count') }}</th>
                            <th class="py-2 text-end">{{ __('ai_studio.admin.credits') }}</th>
                            <th class="py-2 text-end">{{ __('ai_studio.admin.revenue') }}</th>
                            <th class="py-2 text-end">{{ __('ai_studio.admin.cost') }}</th>
                            <th class="py-2 text-end">{{ __('ai_studio.admin.margin_value') }}</th>
                            <th class="py-2 text-end">{{ __('ai_studio.admin.margin_percent') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-3 font-medium">{{ $row['provider'] }} <span class="text-xs text-gray-500">{{ $row['driver'] }}</span></td>
                                <td class="py-3 text-end">{{ number_format($row['jobs']) }}</td>
                                <td class="py-3 text-end">{{ number_format($row['credits']) }}</td>
                                <td class="py-3 text-end">{{ money($row['revenue'], 'QAR') }}</td>
                                <td class="py-3 text-end">{{ money($row['cost'], 'QAR') }}</td>
                                <td @class(['py-3 text-end font-semibold', 'text-danger-600' => $row['margin'] < 0, 'text-success-600' => $row['margin'] >= 0])>{{ money($row['margin'], 'QAR') }}</td>
                                <td class="py-3 text-end">{{ $row['margin_percent'] }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="py-3">Σ</td>
                            <td class="py-3 text-end">{{ number_format(array_sum(array_column($rows, 'jobs'))) }}</td>
                            <td class="py-3 text-end">{{ number_format(array_sum(array_column($rows, 'credits'))) }}</td>
                            <td class="py-3 text-end">{{ money(array_sum(array_column($rows, 'revenue')), 'QAR') }}</td>
                            <td class="py-3 text-end">{{ money(array_sum(array_column($rows, 'cost')), 'QAR') }}</td>
                            <td class="py-3 text-end">{{ money(array_sum(array_column($rows, 'margin')), 'QAR') }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
