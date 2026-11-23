@php($locale = app()->getLocale())
<div class="space-y-6">
    @if (session('status'))<p class="rounded-xl bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</p>@endif

    @forelse ($packages as $cp)
        <article class="card p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-display text-xl font-semibold">{{ $cp->package?->getTranslation('name', $locale) }}</h3>
                    <p class="mt-1 text-xs text-muted">
                        @if ($cp->expires_at){{ __('marketing.packages.expires_on', ['date' => $cp->expires_at->translatedFormat('j M Y')]) }}@else{{ __('marketing.packages.no_expiry') }}@endif
                    </p>
                </div>
                <span @class(['chip text-xs', 'chip-active' => $cp->status === 'active'])>{{ __('marketing.packages.statuses.'.$cp->status) }}</span>
            </div>
            <div class="mt-5">
                <div class="flex justify-between text-sm"><span>{{ __('marketing.packages.sessions_left') }}</span><span class="font-medium">{{ $cp->sessions_remaining }} / {{ $cp->sessions_total }}</span></div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-line"><div class="h-full rounded-full bg-gold" style="width: {{ $cp->sessions_total ? round($cp->sessions_remaining / $cp->sessions_total * 100) : 0 }}%"></div></div>
            </div>
            @if ($cp->usages->isNotEmpty())
                <ul class="mt-4 space-y-1 text-xs text-muted">
                    @foreach ($cp->usages->take(5) as $u)<li>{{ $u->used_at->translatedFormat('j M Y') }} · {{ trans_choice('marketing.packages.sessions', $u->sessions, ['count' => $u->sessions]) }}</li>@endforeach
                </ul>
            @endif
        </article>
    @empty
        <div class="card p-8 text-center">
            <p class="font-display text-lg">{{ __('marketing.packages.account_empty') }}</p>
            @if (tenant()?->hasModule('marketing'))<a href="{{ url('/packages') }}" class="btn-primary mt-5">{{ __('marketing.packages.browse') }}</a>@endif
        </div>
    @endforelse

    @foreach ($memberships as $cm)
        <article class="card p-6">
            <div class="flex items-start justify-between gap-3">
                <h3 class="font-display text-xl font-semibold">{{ $cm->membership?->getTranslation('name', $locale) }}</h3>
                <span @class(['chip text-xs', 'chip-active' => $cm->status === 'active'])>{{ __('marketing.memberships.statuses.'.$cm->status) }}</span>
            </div>
            @if ($cm->renews_at)<p class="mt-2 text-sm text-muted">{{ __('marketing.memberships.renews_on', ['date' => $cm->renews_at->translatedFormat('j M Y')]) }}</p>@endif
        </article>
    @endforeach
</div>
