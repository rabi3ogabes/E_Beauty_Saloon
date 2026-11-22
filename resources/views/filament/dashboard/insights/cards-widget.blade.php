<x-filament-widgets::widget>
    @if ($cards->isNotEmpty())
        <div class="mb-2 flex items-baseline justify-between">
            <h2 class="font-display text-lg font-semibold text-gray-950 dark:text-white">{{ __('insights.hub.widget_title') }}</h2>
            <x-filament::link :href="$hubUrl" size="sm">{{ __('insights.hub.see_all') }}</x-filament::link>
        </div>
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($cards as $card)
                @include('filament.dashboard.insights.partials.card', ['card' => $card])
            @endforeach
        </div>
    @endif
</x-filament-widgets::widget>
