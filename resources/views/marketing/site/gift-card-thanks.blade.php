<x-layouts.site :title="__('marketing.gift_cards.title')">
    <section class="container-luxe py-16 sm:py-24">
        <div class="mx-auto max-w-lg text-center">
            @if ($order->status === 'paid')
                <p class="eyebrow">{{ __('marketing.gift_cards.thanks_eyebrow') }}</p>
                <h1 class="mt-3 text-3xl font-semibold sm:text-4xl">{{ __('marketing.gift_cards.thanks_title') }}</h1>
                <p class="mt-4 text-muted">{{ __('marketing.gift_cards.thanks_body', ['name' => $card?->recipient_name]) }}</p>
                @if ($card)
                    <div class="mt-10">@include('marketing.partials.gift-card-art', ['design' => $card->design, 'amount' => money($card->initial_amount, $card->currency), 'to' => $card->recipient_name, 'from' => $order->purchaser_name, 'salon' => $currentTenant->name])</div>
                @endif
            @elseif ($order->status === 'failed')
                <h1 class="text-3xl font-semibold">{{ __('salon.payments.failed_title') }}</h1>
                <p class="mt-4 text-muted">{{ __('salon.payments.failed_body') }}</p>
                <a href="{{ route('marketing.gift-cards.index') }}" class="btn-primary mt-8">{{ __('marketing.gift_cards.try_again') }}</a>
            @else
                <div class="skeleton mx-auto h-12 w-12 rounded-full"></div>
                <h1 class="mt-6 text-3xl font-semibold">{{ __('salon.payments.pending_title') }}</h1>
                <p class="mt-4 text-muted">{{ __('salon.payments.pending_body') }}</p>
                <meta http-equiv="refresh" content="5">
            @endif
        </div>
    </section>
</x-layouts.site>
