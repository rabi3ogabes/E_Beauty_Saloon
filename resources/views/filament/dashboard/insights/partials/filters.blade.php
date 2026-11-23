<div class="flex flex-wrap items-end gap-3">
    <label class="text-sm">
        <span class="block text-gray-500">{{ __('insights.reports.from') }}</span>
        <input type="date" wire:model.live="from" class="rounded-[10px] border border-gray-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-white/5">
    </label>
    <label class="text-sm">
        <span class="block text-gray-500">{{ __('insights.reports.until') }}</span>
        <input type="date" wire:model.live="until" class="rounded-[10px] border border-gray-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-white/5">
    </label>
    @if (($withBranch ?? true) && $this->branches->count() > 1)
        <label class="text-sm">
            <span class="block text-gray-500">{{ __('insights.reports.branch') }}</span>
            <select wire:model.live="branchId" class="rounded-[10px] border border-gray-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-white/5">
                <option value="">{{ __('salon.ui.all_branches') }}</option>
                @foreach ($this->branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
        </label>
    @endif
    <div class="flex flex-wrap gap-2">
        @foreach (['last_7', 'last_30', 'this_month', 'last_month', 'last_90'] as $preset)
            <x-filament::button color="gray" size="sm" class="rounded-full" wire:click="setPeriod('{{ $preset }}')">{{ __('insights.reports.presets.'.$preset) }}</x-filament::button>
        @endforeach
    </div>
</div>
