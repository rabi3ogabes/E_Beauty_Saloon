<x-layouts.site :title="__('marketing.gift_cards.card_title', ['name' => $card->recipient_name])">
    <section class="container-luxe py-12 sm:py-20">
        <div class="mx-auto max-w-xl text-center" data-reveal>
            <p class="eyebrow">{{ __('marketing.gift_cards.for_you') }}</p>
            <h1 class="mt-3 text-3xl font-semibold sm:text-4xl">{{ __('marketing.gift_cards.card_title', ['name' => $card->recipient_name]) }}</h1>
        </div>
        <div class="mx-auto mt-10 max-w-lg" data-reveal>
            @include('marketing.partials.gift-card-art', [
                'design' => $card->design, 'amount' => money($card->initial_amount, $card->currency), 'to' => $card->recipient_name,
                'from' => null, 'code' => $card->code, 'salon' => $currentTenant->name,
            ])
            @if ($card->message)
                <blockquote class="card mt-8 p-6 text-center font-display text-xl italic leading-relaxed">“{{ $card->message }}”</blockquote>
            @endif
            <div class="card mt-6 grid gap-4 p-6 text-sm sm:grid-cols-3">
                <div><p class="text-muted">{{ __('marketing.gift_cards.balance') }}</p><p class="mt-1 font-display text-xl">{{ money($card->balance, $card->currency) }}</p></div>
                <div><p class="text-muted">{{ __('marketing.gift_cards.code') }}</p><p class="mt-1 font-mono tracking-wider" dir="ltr">{{ $card->code }}</p></div>
                <div><p class="text-muted">{{ __('marketing.gift_cards.valid_until') }}</p><p class="mt-1">{{ $card->expires_at?->translatedFormat('j M Y') ?? '—' }}</p></div>
            </div>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                @if ($currentTenant->hasModule('booking'))<a href="{{ url('/book') }}" class="btn-primary">{{ __('marketing.gift_cards.book_with_it') }}</a>@endif
                <a href="{{ url('/') }}" class="btn-ghost">{{ __('marketing.gift_cards.discover', ['salon' => $currentTenant->name]) }}</a>
            </div>
            <p class="mt-6 text-center text-xs text-muted">{{ __('marketing.gift_cards.how_to_use') }}</p>
        </div>
    </section>
</x-layouts.site>
