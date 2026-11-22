<x-filament-panels::page>
    <form wire:submit="generate" class="space-y-4">
        {{ $this->form }}
        <div class="flex justify-end">
            <x-filament::button type="submit" icon="heroicon-m-sparkles" class="rounded-full" wire:loading.attr="disabled" wire:target="generate">{{ __('insights.content.generate') }}</x-filament::button>
        </div>
    </form>

    <div wire:loading.delay wire:target="generate" class="grid gap-4 md:grid-cols-2">
        <div class="h-36 animate-pulse rounded-2xl bg-gray-100 dark:bg-white/5"></div>
        <div class="h-36 animate-pulse rounded-2xl bg-gray-100 dark:bg-white/5"></div>
    </div>

    @if ($result)
        <div wire:loading.remove wire:target="generate" class="grid gap-4 md:grid-cols-2">
            @foreach (['en' => 'English', 'ar' => 'العربية'] as $locale => $label)
                <x-filament::section :heading="$label" compact>
                    <div x-data dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
                        <p class="whitespace-pre-line text-sm leading-relaxed text-gray-800 dark:text-gray-200">{{ $result[$locale] ?: '—' }}</p>
                        <button type="button" class="mt-3 text-xs text-primary-600 hover:underline" x-on:click="navigator.clipboard.writeText(@js($result[$locale])); $tooltip(@js(__('salon.ui.copied')))">{{ __('salon.ui.copy') }}</button>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
        <div class="flex items-center justify-between text-xs text-gray-500">
            <span>{{ trans_choice('insights.content.used', $result['credits'], ['count' => $result['credits']]) }}</span>
            @if (($data['kind'] ?? null) === 'service_description' && ! empty($data['service_id']) && auth()->user()->can('catalog.manage'))
                <x-filament::button size="sm" color="gray" class="rounded-full" wire:click="applyToService">{{ __('insights.content.apply') }}</x-filament::button>
            @endif
        </div>
    @endif
</x-filament-panels::page>
