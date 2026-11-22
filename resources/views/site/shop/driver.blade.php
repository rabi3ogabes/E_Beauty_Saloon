@php use App\Domain\Shop\Enums\OrderStatus; @endphp
<x-layouts.site :title="__('shop.driver.title')">
    @push('head')<meta name="robots" content="noindex,nofollow"><meta http-equiv="refresh" content="120">@endpush
    <section class="mx-auto w-full max-w-lg px-4 py-8">
        <p class="eyebrow">{{ __('shop.driver.title') }}</p>
        <h1 class="mt-2 text-2xl font-semibold">{{ __('shop.driver.hello', ['name' => $driver->name]) }}</h1>
        @if (session('status'))<p class="mt-4 rounded-xl bg-success/10 px-4 py-3 text-sm text-success" role="status">{{ session('status') }}</p>@endif
        @error('driver')<p class="mt-4 rounded-xl bg-danger/10 px-4 py-3 text-sm text-danger">{{ $message }}</p>@enderror

        @if ($orders->isEmpty())
            <div class="card mt-6 p-8 text-center text-sm text-muted">{{ __('shop.driver.empty') }}</div>
        @endif

        <ul class="mt-6 space-y-4">
            @foreach ($orders as $order)
                @php
                    $a = $order->address ?? [];
                    $line = collect([$a['unit'] ?? null, $a['building'] ?? null, $a['street'] ?? null, $a['area'] ?? null])->filter()->implode(', ');
                    $map = ! empty($a['lat']) ? 'https://www.openstreetmap.org/?mlat='.$a['lat'].'&mlon='.$a['lng'].'#map=17/'.$a['lat'].'/'.$a['lng'] : 'https://www.openstreetmap.org/search?query='.urlencode($line.' Doha Qatar');
                    $wa = 'https://wa.me/'.ltrim($order->customer_phone, '+');
                    $done = $order->status === OrderStatus::Delivered;
                @endphp
                <li @class(['card p-5', 'opacity-60' => $done])>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold">{{ $order->number }} · {{ $order->customer_name }}</p>
                            <p class="mt-0.5 text-sm text-muted">{{ $line ?: '—' }}</p>
                            @if (! empty($a['notes']))<p class="mt-1 text-xs italic text-muted">“{{ $a['notes'] }}”</p>@endif
                            @if ($order->delivery_slot)<p class="mt-1 text-xs text-gold-dark" dir="ltr">{{ __('shop.driver.slot', ['slot' => $order->delivery_slot]) }}</p>@endif
                        </div>
                        <span class="shrink-0 rounded-full bg-ivory px-2.5 py-1 text-xs dark:bg-white/5">{{ $order->status->label() }}</span>
                    </div>
                    <p class="mt-3 text-sm">{{ __('shop.driver.items', ['count' => $order->items->sum('qty')]) }} ·
                        @if ($order->balanceDue() > 0)<strong class="text-danger">{{ __('shop.driver.collect', ['amount' => money($order->balanceDue(), $order->currency)]) }}</strong>
                        @else<span class="text-success">{{ __('shop.driver.prepaid') }}</span>@endif
                    </p>
                    <div class="mt-4 grid grid-cols-3 gap-2">
                        <a href="{{ $map }}" target="_blank" rel="noopener" class="btn-ghost !px-2">{{ __('shop.driver.map') }}</a>
                        <a href="tel:{{ $order->customer_phone }}" class="btn-ghost !px-2">{{ __('shop.driver.call') }}</a>
                        <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn-ghost !px-2">{{ __('shop.driver.whatsapp') }}</a>
                    </div>
                    @unless ($done)
                        <form method="POST" action="{{ $actionUrl($order) }}" class="mt-3 space-y-2">
                            @csrf
                            @if ($order->status !== OrderStatus::OutForDelivery)
                                <input type="hidden" name="step" value="out">
                                <button class="btn-gold w-full">{{ __('shop.driver.start') }}</button>
                            @else
                                <input type="hidden" name="step" value="delivered">
                                @if ($order->balanceDue() > 0)
                                    <div class="grid grid-cols-2 gap-2">
                                        <button name="cod_method" value="cash" class="btn-primary">{{ __('shop.driver.collect_cash') }}</button>
                                        <button name="cod_method" value="card_terminal" class="btn-primary">{{ __('shop.driver.collect_card') }}</button>
                                    </div>
                                @else
                                    <button class="btn-primary w-full">{{ __('shop.driver.delivered') }}</button>
                                @endif
                            @endif
                        </form>
                    @endunless
                </li>
            @endforeach
        </ul>
    </section>
</x-layouts.site>
