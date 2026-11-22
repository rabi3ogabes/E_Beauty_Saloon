@php
    $icons = ['off_peak' => 'heroicon-o-clock', 'reorder' => 'heroicon-o-archive-box', 'churn_risk' => 'heroicon-o-user-minus', 'no_show' => 'heroicon-o-calendar-days',
        'peak_pricing' => 'heroicon-o-arrow-trending-up', 'revenue_up' => 'heroicon-o-arrow-trending-up', 'revenue_down' => 'heroicon-o-arrow-trending-down', 'demand' => 'heroicon-o-user-group'];
    $type = $card->actionType();
    $pages = ['business-settings' => \App\Filament\Dashboard\Pages\BusinessSettings::class, 'reports/revenue' => \App\Filament\Dashboard\Pages\Reports\RevenueReport::class];
@endphp
<article wire:key="card-{{ $card->id }}" @class([
    'flex h-full flex-col rounded-2xl border bg-white p-5 shadow-sm transition duration-200 ease-out hover:shadow-md dark:bg-white/5',
    'border-primary-200 dark:border-primary-500/30' => $card->isOpen(),
    'border-gray-100 opacity-75 dark:border-white/10' => ! $card->isOpen(),
])>
    <div class="flex items-start gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full" style="background: #E9D6CF">
            <x-filament::icon :icon="$icons[$card->type] ?? 'heroicon-o-light-bulb'" class="h-5 w-5" style="color: #A88B4F" />
        </span>
        <div class="min-w-0">
            <h3 class="font-display text-base font-semibold leading-snug text-gray-950 dark:text-white">{{ $card->localized('title') }}</h3>
            <p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-300">{{ $card->localized('body') }}</p>
        </div>
    </div>
    <div class="mt-auto flex flex-wrap items-center gap-2 pt-4">
        @if ($card->isOpen())
            @if (in_array($type, ['campaign_draft', 'purchase_order_draft', 'pricing_review'], true))
                <x-filament::button size="sm" class="rounded-full" wire:click="act({{ $card->id }})" wire:loading.attr="disabled" wire:target="act({{ $card->id }})">
                    {{ __('insights.cards.actions.'.$type) }}
                </x-filament::button>
            @elseif ($type === 'open_page' && isset($pages[$card->action['params']['page'] ?? '']) && $pages[$card->action['params']['page']]::canAccess())
                <x-filament::button size="sm" color="gray" class="rounded-full" tag="a" :href="$pages[$card->action['params']['page']]::getUrl()">{{ __('insights.cards.actions.open_page') }}</x-filament::button>
            @endif
            <x-filament::link size="sm" color="gray" tag="button" wire:click="dismiss({{ $card->id }})">{{ __('insights.cards.dismiss') }}</x-filament::link>
        @else
            <x-filament::badge :color="$card->status === 'approved' ? 'success' : 'gray'">{{ __('insights.cards.status.'.$card->status) }}</x-filament::badge>
        @endif
        <span class="ms-auto text-[11px] uppercase tracking-wide text-gray-400" title="{{ $card->source === 'ai' ? __('insights.cards.by_ai') : __('insights.cards.by_rules') }}">
            {{ $card->period_key }} · {{ $card->source === 'ai' ? 'AI' : __('insights.cards.rules') }}
        </span>
    </div>
</article>
