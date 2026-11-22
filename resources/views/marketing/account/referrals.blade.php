<div class="space-y-6" x-data="{ copied: false }">
    <div class="card p-6 sm:p-8">
        <p class="eyebrow">{{ __('marketing.referrals.account_eyebrow') }}</p>
        <h3 class="mt-2 font-display text-2xl font-semibold">{{ __('marketing.referrals.account_title', ['points' => number_format($points), 'discount' => $discount]) }}</h3>
        <div class="mt-6 flex flex-col gap-3 sm:flex-row">
            <input class="input flex-1 bg-ivory" value="{{ $link }}" dir="ltr" readonly aria-label="{{ __('marketing.referrals.your_link') }}">
            <button type="button" class="btn-primary" @click="navigator.clipboard.writeText(@js($link)); copied = true; setTimeout(() => copied = false, 2000)">
                <span x-show="!copied">{{ __('salon.ui.copy') }}</span><span x-show="copied" x-cloak>{{ __('salon.ui.copied') }}</span>
            </button>
            <a class="btn-ghost" target="_blank" rel="noopener" href="https://wa.me/?text={{ rawurlencode(__('marketing.referrals.share_text', ['salon' => tenant()?->name, 'link' => $link])) }}">WhatsApp</a>
        </div>
    </div>
    <div class="card p-6">
        <h3 class="font-display text-lg">{{ __('marketing.referrals.your_friends') }}</h3>
        <ul class="mt-4 divide-y divide-line text-sm">
            @forelse ($referrals as $r)
                <li class="flex items-center justify-between py-3">
                    <span>{{ $r->referred?->firstName() }}</span>
                    <span @class(['chip text-xs', 'chip-active' => $r->status === 'rewarded'])>{{ $r->status === 'rewarded' ? '+'.number_format($r->points_awarded).' '.__('marketing.loyalty.points') : __('marketing.referrals.statuses.'.$r->status) }}</span>
                </li>
            @empty
                <li class="py-3 text-muted">{{ __('marketing.referrals.none_yet') }}</li>
            @endforelse
        </ul>
    </div>
</div>
