@php($rows = $this->rows)
<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm">
            <span class="block text-gray-500">{{ __('pos.commissions.from') }}</span>
            <input type="date" wire:model.live="from" class="fi-input rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-white/5">
        </label>
        <label class="text-sm">
            <span class="block text-gray-500">{{ __('pos.commissions.until') }}</span>
            <input type="date" wire:model.live="until" class="fi-input rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-white/5">
        </label>
        @if ($this->branches->count() > 1)
            <label class="text-sm">
                <span class="block text-gray-500">{{ __('pos.commissions.branch') }}</span>
                <select wire:model.live="branchId" class="rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-white/5">
                    <option value="">{{ __('salon.ui.all_branches') }}</option>
                    @foreach ($this->branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <div class="flex gap-2">
            <x-filament::button color="gray" size="sm" wire:click="setPeriod('this_month')">{{ now()->translatedFormat('F') }}</x-filament::button>
            <x-filament::button color="gray" size="sm" wire:click="setPeriod('last_month')">{{ now()->subMonthNoOverflow()->translatedFormat('F') }}</x-filament::button>
            <x-filament::button color="gray" size="sm" wire:click="setPeriod('last_7')">7d</x-filament::button>
        </div>
    </div>

    <x-filament::section>
        <div wire:loading.delay wire:target="from,until,branchId,setPeriod" class="space-y-2">
            @for ($i = 0; $i < 4; $i++)<div class="h-10 animate-pulse rounded-lg bg-gray-100 dark:bg-white/5"></div>@endfor
        </div>
        <div wire:loading.remove wire:target="from,until,branchId,setPeriod" class="relative overflow-x-auto">
            @if (empty($rows))
                <div class="py-10 text-center text-gray-500">
                    <x-filament::icon icon="heroicon-o-currency-dollar" class="mx-auto mb-2 h-8 w-8 text-primary-500" />
                    {{ __('pos.commissions.empty') }}
                </div>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-start text-xs uppercase tracking-wide text-gray-500 dark:border-white/10">
                            <th class="py-2 text-start">{{ __('pos.commissions.staff') }}</th>
                            <th class="py-2 text-end">{{ __('pos.commissions.lines') }}</th>
                            <th class="py-2 text-end">{{ __('pos.commissions.services') }}</th>
                            <th class="py-2 text-end">{{ __('pos.commissions.products') }}</th>
                            <th class="py-2 text-end">{{ __('pos.commissions.commission') }}</th>
                            <th class="py-2 text-end">{{ __('pos.commissions.tips') }}</th>
                            <th class="py-2 text-end">{{ __('pos.commissions.payout') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5" wire:key="c-{{ $row['staff_id'] }}">
                                <td class="py-3 font-semibold">{{ $row['staff'] }}</td>
                                <td class="py-3 text-end">{{ $row['lines'] }}</td>
                                <td class="py-3 text-end">{{ money($row['services']) }}</td>
                                <td class="py-3 text-end">{{ money($row['products']) }}</td>
                                <td class="py-3 text-end">{{ money($row['commission']) }}</td>
                                <td class="py-3 text-end">{{ money($row['tips']) }}</td>
                                <td class="py-3 text-end font-semibold text-primary-600">{{ money($row['payout']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="py-3">{{ __('pos.ticket.total') }}</td>
                            <td class="py-3 text-end">{{ array_sum(array_column($rows, 'lines')) }}</td>
                            <td class="py-3 text-end">{{ money(array_sum(array_column($rows, 'services'))) }}</td>
                            <td class="py-3 text-end">{{ money(array_sum(array_column($rows, 'products'))) }}</td>
                            <td class="py-3 text-end">{{ money(array_sum(array_column($rows, 'commission'))) }}</td>
                            <td class="py-3 text-end">{{ money(array_sum(array_column($rows, 'tips'))) }}</td>
                            <td class="py-3 text-end">{{ money(array_sum(array_column($rows, 'payout'))) }}</td>
                        </tr>
                    </tfoot>
                </table>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
