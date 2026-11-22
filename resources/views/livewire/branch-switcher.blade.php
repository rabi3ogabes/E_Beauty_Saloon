<div class="hidden sm:block">
    @if ($branches->count() > 1 || ! $canSeeAll)
        <label class="sr-only" for="branch-switcher">{{ __('salon.ui.branch') }}</label>
        <div class="relative flex items-center">
            <x-filament::icon icon="heroicon-o-map-pin" class="pointer-events-none absolute start-3 h-4 w-4 text-primary-600" />
            <select id="branch-switcher" wire:model.live="branchId"
                    class="min-h-10 rounded-full border border-gray-200 bg-white ps-9 pe-8 text-sm font-medium text-gray-800 shadow-sm focus:border-primary-400 focus:ring-primary-400/30 dark:border-white/10 dark:bg-white/5 dark:text-gray-100">
                @if ($canSeeAll)
                    <option value="">{{ __('salon.ui.all_branches') }}</option>
                @endif
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
    @endif
</div>
