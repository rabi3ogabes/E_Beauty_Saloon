<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <div class="flex flex-wrap justify-end gap-3">
            <x-filament::button tag="a" color="gray" :href="url('/')" target="_blank">{{ __('website.dashboard.preview') }}</x-filament::button>
            <x-filament::button type="submit">{{ __('salon.ui.save') }}</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
