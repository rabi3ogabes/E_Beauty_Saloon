@if ($orders->isEmpty())
    <div class="card flex flex-col items-center px-6 py-14 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-rose/50 text-xl text-gold-dark">✦</span>
        <p class="mt-4 font-display text-xl">{{ __('shop.account.empty') }}</p>
        <p class="mt-1 text-sm text-muted">{{ __('shop.account.empty_sub') }}</p>
        <a href="{{ route('shop.index') }}" class="btn-primary mt-6">{{ __('shop.account.empty_cta') }}</a>
    </div>
@else
    <ul class="space-y-3">
        @foreach ($orders as $order)
            <li class="card flex flex-wrap items-center justify-between gap-4 p-5">
                <div>
                    <p class="font-medium">{{ $order->number }} <span class="ms-2 rounded-full bg-ivory px-2.5 py-0.5 text-xs dark:bg-white/5">{{ $order->status->label() }}</span></p>
                    <p class="mt-1 text-xs text-muted">{{ __('shop.account.placed_on', ['date' => $order->created_at->translatedFormat('j M Y')]) }} · {{ __('shop.account.items', ['count' => $order->items_count]) }} · {{ __('shop.fulfilment.'.$order->fulfilment) }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <p class="font-medium">{{ money($order->total, $order->currency) }}</p>
                    <a href="{{ route('client.account', ['section' => 'orders', 'order' => $order->number]) }}" class="btn-ghost !min-h-10 !px-4">{{ __('shop.account.view') }}</a>
                </div>
            </li>
        @endforeach
    </ul>
@endif
