<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($stats as $key => $value)
            <x-filament::section>
                <p class="text-xs uppercase tracking-wider text-gray-500">{{ __('marketing.loyalty.stats.'.$key) }}</p>
                <p class="mt-2 font-display text-2xl font-semibold text-gray-950 dark:text-white">{{ $value }}</p>
            </x-filament::section>
        @endforeach
    </div>

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        @if (\App\Filament\Marketing\MarketingAccess::manage(['loyalty']))
            <x-filament::button type="submit" icon="heroicon-o-check">{{ __('marketing.common.save') }}</x-filament::button>
        @endif
    </form>
</x-filament-panels::page>
