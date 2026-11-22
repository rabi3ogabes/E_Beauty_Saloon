<div class="space-y-4">
    <a href="{{ route('client.account', 'orders') }}" class="text-sm text-muted hover:text-ink">← {{ __('shop.account.back') }}</a>
    <div class="card p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="eyebrow">{{ __('shop.account.order') }}</p>
                <h2 class="mt-1 text-2xl font-semibold">{{ $order->number }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('shop.account.placed_on', ['date' => $order->created_at->translatedFormat('j M Y, H:i')]) }} · {{ __('shop.fulfilment.'.$order->fulfilment) }}</p>
            </div>
            <span class="rounded-full bg-ivory px-3 py-1 text-sm dark:bg-white/5">{{ $order->status->label() }}</span>
        </div>
        <ul class="mt-5 divide-y hairline text-sm">
            @foreach ($order->items as $item)
                <li class="flex justify-between gap-4 py-3"><span>{{ $item->qty }} × {{ $item->label() }}</span><span>{{ money($item->total, $order->currency) }}</span></li>
            @endforeach
        </ul>
        <div class="mt-4 flex justify-between border-t hairline pt-4 font-semibold"><span>{{ __('shop.checkout.total') }}</span><span>{{ money($order->total, $order->currency) }}</span></div>
        <ol class="mt-6 space-y-2 border-s hairline ps-4 text-sm">
            @foreach ($order->history as $h)
                <li><span class="font-medium">{{ \App\Domain\Shop\Enums\OrderStatus::from($h->to_status)->label() }}</span> <span class="text-muted">· {{ $h->created_at?->translatedFormat('j M, H:i') }}</span></li>
            @endforeach
        </ol>
        <div class="mt-6 flex flex-wrap gap-3">
            <a href="{{ $order->trackingUrl() }}" class="btn-ghost">{{ __('shop.account.track') }}</a>
            <form method="POST" action="{{ route('shop.orders.reorder', $order->number) }}">@csrf<button class="btn-primary">{{ __('shop.account.reorder') }}</button></form>
        </div>
    </div>
</div>
