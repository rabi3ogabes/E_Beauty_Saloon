<x-layouts.site :title="__('marketing.referrals.landing_title', ['name' => $referrer->firstName()])">
    <section class="container-luxe py-16 sm:py-24">
        <div class="mx-auto max-w-xl text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gold/15 font-display text-2xl text-gold-dark">✦</div>
            <p class="eyebrow mt-6">{{ __('marketing.referrals.landing_eyebrow') }}</p>
            <h1 class="mt-3 text-4xl font-semibold sm:text-5xl">{{ __('marketing.referrals.landing_title', ['name' => $referrer->firstName()]) }}</h1>
            <p class="mt-5 text-lg text-muted">{{ __('marketing.referrals.landing_body', ['discount' => $discount, 'salon' => $currentTenant->name]) }}</p>

            @if ($referral?->promotion)
                <div class="card mt-10 p-6">
                    <p class="text-sm text-muted">{{ __('marketing.referrals.your_code') }}</p>
                    <p class="mt-2 font-mono text-2xl tracking-[0.2em]" dir="ltr">{{ $referral->promotion->code }}</p>
                </div>
                @if ($currentTenant->hasModule('booking'))<a href="{{ url('/book') }}" class="btn-primary mt-8">{{ __('site.cta.book_now') }}</a>@endif
            @elseif ($friend)
                <p class="card mt-10 p-6 text-sm text-muted">{{ __('marketing.referrals.not_eligible') }}</p>
            @else
                <a href="{{ route('client.login') }}" class="btn-primary mt-10">{{ __('marketing.referrals.claim') }}</a>
                <p class="mt-4 text-xs text-muted">{{ __('marketing.referrals.claim_note') }}</p>
            @endif
        </div>
    </section>
</x-layouts.site>
