@php($locale = app()->getLocale())
<div class="space-y-6">
    <div class="card relative overflow-hidden p-6 sm:p-8">
        <div class="absolute -end-10 -top-10 h-40 w-40 rounded-full bg-gold/15"></div>
        <p class="eyebrow">{{ __('marketing.loyalty.your_tier') }}</p>
        <p class="mt-2 font-display text-3xl font-semibold">{{ __('marketing.loyalty.tiers.'.($current['key'] ?? 'silver')) }}</p>
        <p class="mt-2 text-sm text-muted">{{ data_get($current, 'perks.'.$locale) ?? data_get($current, 'perks.en') }}</p>
        <div class="mt-6 grid grid-cols-2 gap-4">
            <div><p class="text-xs text-muted">{{ __('marketing.loyalty.balance') }}</p><p class="mt-1 font-display text-2xl">{{ number_format($client->loyalty_points) }}</p></div>
            <div><p class="text-xs text-muted">{{ __('marketing.loyalty.worth') }}</p><p class="mt-1 font-display text-2xl">{{ money($value) }}</p></div>
        </div>
        @if ($next)
            @php($progress = min(100, round($lifetime / max(1, $next['min_points']) * 100)))
            <div class="mt-6">
                <div class="flex justify-between text-xs text-muted"><span>{{ __('marketing.loyalty.to_next', ['points' => number_format($next['min_points'] - $lifetime), 'tier' => __('marketing.loyalty.tiers.'.$next['key'])]) }}</span><span>{{ $progress }}%</span></div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-line"><div class="h-full rounded-full bg-gold" style="width: {{ $progress }}%"></div></div>
            </div>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ($tiers as $tier)
            <div @class(['card p-5', 'ring-1 ring-gold' => $tier['key'] === ($current['key'] ?? 'silver')])>
                <p class="font-display text-lg">{{ __('marketing.loyalty.tiers.'.$tier['key']) }}</p>
                <p class="text-xs text-muted">{{ __('marketing.loyalty.from_points', ['points' => number_format($tier['min_points'])]) }}</p>
                <p class="mt-3 text-sm">{{ data_get($tier, 'perks.'.$locale) ?? data_get($tier, 'perks.en') }}</p>
            </div>
        @endforeach
    </div>

    <div class="card p-6">
        <h3 class="font-display text-lg">{{ __('marketing.loyalty.history') }}</h3>
        <ul class="mt-4 divide-y divide-line text-sm">
            @forelse ($entries as $e)
                <li class="flex items-center justify-between py-3">
                    <span>{{ __('marketing.loyalty.entry_types.'.$e->type) }}<span class="block text-xs text-muted">{{ $e->created_at->translatedFormat('j M Y') }}</span></span>
                    <span @class(['font-medium', 'text-success' => $e->points > 0, 'text-danger' => $e->points < 0])>{{ $e->points > 0 ? '+' : '' }}{{ number_format($e->points) }}</span>
                </li>
            @empty
                <li class="py-3 text-muted">{{ __('marketing.loyalty.no_history') }}</li>
            @endforelse
        </ul>
    </div>
</div>
