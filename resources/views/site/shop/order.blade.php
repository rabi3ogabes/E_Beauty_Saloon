@php
    use App\Domain\Shop\Enums\OrderStatus;
    $tz = $order->branch?->timezone ?: 'Asia/Qatar';
    $steps = $order->isPickup()
        ? [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::ReadyForPickup, OrderStatus::Delivered]
        : [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::OutForDelivery, OrderStatus::Delivered];
    $reached = $order->history->pluck('to_status')->all();
    $currentIndex = array_search($order->status, $steps, true);
    $closed = in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Returned], true);
@endphp
<x-shop.shell :title="__('shop.order.title', ['number' => $order->number])" :bar="false">
    @push('head')<meta name="robots" content="noindex,nofollow">@endpush
    <section class="container-luxe max-w-4xl py-12 sm:py-16">
        <p class="eyebrow">{{ __('shop.order.title', ['number' => $order->number]) }}</p>
        <h1 class="mt-3 text-3xl font-semibold sm:text-4xl">{{ __('shop.order.thanks', ['name' => explode(' ', trim($order->customer_name))[0]]) }}</h1>
        <p class="mt-2 text-muted">{{ __('shop.order.thanks_sub') }}</p>

        @if (session('status'))<p class="mt-6 rounded-xl bg-success/10 px-4 py-3 text-sm text-success" role="status">{{ session('status') }}</p>@endif

        {{-- Payment states --}}
        @if ($order->status === OrderStatus::Pending && $pendingPayment)
            <div class="card mt-6 flex flex-wrap items-center justify-between gap-4 border-gold/50 p-5">
                <p class="text-sm">{{ __('shop.order.payment_pending') }}</p>
                @if ($pendingPayment->checkout_url)<a href="{{ $pendingPayment->checkout_url }}" class="btn-gold">{{ __('shop.order.pay_now') }}</a>@endif
            </div>
        @elseif ($order->status === OrderStatus::Cancelled && $order->cancel_reason === 'payment_failed')
            <div class="card mt-6 flex flex-wrap items-center justify-between gap-4 p-5">
                <p class="text-sm text-danger">{{ __('shop.order.payment_failed') }}</p>
                <a href="{{ route('shop.checkout') }}" class="btn-primary">{{ __('shop.cart.checkout') }}</a>
            </div>
        @endif

        {{-- COD OTP confirmation --}}
        @if ($order->cod_otp_required && ! $order->cod_otp_verified_at && $order->status === OrderStatus::Pending)
            <div class="card mt-6 p-6">
                <h2 class="text-xl font-semibold">{{ __('shop.order.otp_title') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('shop.order.otp_sub', ['phone' => $order->customer_phone]) }}</p>
                <form method="POST" action="{{ route('shop.orders.otp', $order->public_token) }}" class="mt-4 flex flex-wrap gap-2">
                    @csrf
                    <label for="otp" class="sr-only">{{ __('shop.checkout.code') }}</label>
                    <input id="otp" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" dir="ltr" class="input max-w-48 tracking-[0.5em]" required>
                    <button class="btn-primary">{{ __('shop.order.otp_confirm') }}</button>
                </form>
                @error('code')<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
                <form method="POST" action="{{ route('shop.orders.otp.resend', $order->public_token) }}" class="mt-3">@csrf<button class="text-xs text-gold-dark underline underline-offset-4">{{ __('shop.order.otp_resend') }}</button></form>
            </div>
        @endif

        {{-- Progress --}}
        <section class="card mt-6 p-6 sm:p-8" aria-label="{{ __('shop.order.timeline') }}">
            @if ($closed)
                <p class="flex items-center gap-2 text-danger"><span class="h-2.5 w-2.5 rounded-full bg-danger"></span>{{ $order->status->label() }}@if ($order->cancel_reason) · {{ __('shop.cancel_reasons.'.$order->cancel_reason) }}@endif</p>
            @else
                <ol class="grid grid-cols-5 gap-2">
                    @foreach ($steps as $i => $step)
                        @php $done = $currentIndex !== false && $i <= $currentIndex; @endphp
                        <li class="text-center">
                            <span @class(['mx-auto flex h-9 w-9 items-center justify-center rounded-full text-xs transition', 'bg-ink text-ivory dark:bg-gold dark:text-ink' => $done, 'border hairline text-muted' => ! $done])>{{ $done ? '✓' : $i + 1 }}</span>
                            <span @class(['mt-2 block text-[11px] leading-tight sm:text-xs', 'font-medium' => $i === $currentIndex, 'text-muted' => $i !== $currentIndex])>{{ $step->label() }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
            <div class="mt-6 grid gap-4 border-t hairline pt-6 text-sm sm:grid-cols-2">
                @if ($order->isPickup())
                    <div><p class="label">{{ __('shop.order.pickup_at') }}</p><p>{{ $order->branch?->name }}</p><p class="text-muted">{{ $order->branch?->address }}</p></div>
                    @if ($order->ready_estimate_at)<div><p class="label">{{ __('shop.order.ready_by') }}</p><p>{{ $order->ready_estimate_at->setTimezone($tz)->translatedFormat('l j M, H:i') }}</p></div>@endif
                @else
                    <div><p class="label">{{ __('shop.order.deliver_to') }}</p><p>{{ collect([$order->address['unit'] ?? null, $order->address['building'] ?? null, $order->address['street'] ?? null, $order->address['area'] ?? null])->filter()->implode(', ') }}</p><p class="text-muted">{{ $order->zone?->name }}</p></div>
                    <div>
                        @if ($order->delivery_slot)<p class="label">{{ __('shop.order.slot') }}</p><p dir="ltr" class="text-start">{{ $order->delivery_slot }}</p>
                        @elseif ($order->ready_estimate_at)<p class="label">{{ __('shop.checkout.eta', ['time' => '']) }}</p><p>{{ $order->ready_estimate_at->setTimezone($tz)->translatedFormat('l j M') }}</p>@endif
                        @if ($order->driver && $order->status === OrderStatus::OutForDelivery)
                            <p class="mt-2">{{ __('shop.order.driver', ['name' => $order->driver->name]) }} @if ($order->driver->phone)<a href="tel:{{ $order->driver->phone }}" class="ms-2 text-gold-dark underline underline-offset-4">{{ __('shop.order.call_driver') }}</a>@endif</p>
                        @endif
                    </div>
                @endif
                @if ($order->gift_message)<div class="sm:col-span-2"><p class="label">{{ __('shop.order.gift') }}</p><p class="italic">“{{ $order->gift_message }}”</p></div>@endif
            </div>
        </section>

        {{-- Items & totals --}}
        <section class="card mt-6 p-6 sm:p-8">
            <h2 class="text-lg font-semibold">{{ __('shop.order.items') }}</h2>
            <ul class="mt-4 divide-y hairline text-sm">
                @foreach ($order->items as $item)
                    <li class="flex justify-between gap-4 py-3"><span>{{ $item->qty }} × {{ $item->label() }}</span><span>{{ money($item->total, $order->currency) }}</span></li>
                @endforeach
            </ul>
            <dl class="mt-4 space-y-1.5 border-t hairline pt-4 text-sm">
                <div class="flex justify-between"><dt class="text-muted">{{ __('shop.cart.subtotal') }}</dt><dd>{{ money($order->subtotal, $order->currency) }}</dd></div>
                @if ($order->discount)<div class="flex justify-between text-success"><dt>{{ __('shop.cart.discount') }} {{ $order->coupon_code }}</dt><dd>−{{ money($order->discount, $order->currency) }}</dd></div>@endif
                @if (! $order->isPickup())<div class="flex justify-between"><dt class="text-muted">{{ __('shop.checkout.delivery_fee') }}</dt><dd>{{ $order->delivery_fee ? money($order->delivery_fee, $order->currency) : __('shop.checkout.free') }}</dd></div>@endif
                @if ($order->gift_wrap_fee)<div class="flex justify-between"><dt class="text-muted">{{ __('shop.cart.gift_wrap') }}</dt><dd>{{ money($order->gift_wrap_fee, $order->currency) }}</dd></div>@endif
                @if ($order->tax && ! $order->tax_inclusive)<div class="flex justify-between"><dt class="text-muted">{{ __('shop.checkout.tax') }}</dt><dd>{{ money($order->tax, $order->currency) }}</dd></div>@endif
                <div class="flex justify-between pt-2 text-base font-semibold"><dt>{{ __('shop.checkout.total') }}</dt><dd>{{ money($order->total, $order->currency) }}</dd></div>
                <div class="flex justify-between text-muted"><dt>{{ __('shop.order.payment_method') }}</dt><dd>{{ __('shop.payment_methods.'.$order->payment_method) }} · {{ __('shop.payment_status.'.$order->paymentStatus()) }}</dd></div>
                @if ($order->balanceDue() > 0 && $order->isCod() && ! $closed)<div class="flex justify-between font-medium"><dt>{{ __('shop.order.balance_due') }}</dt><dd>{{ money($order->balanceDue(), $order->currency) }}</dd></div>@endif
                @if ($order->refunded)<div class="flex justify-between text-success"><dt>{{ __('shop.order.refunded') }}</dt><dd>{{ money($order->refunded, $order->currency) }}</dd></div>@endif
            </dl>
        </section>

        <div class="mt-8 flex flex-wrap gap-3">
            <a href="{{ route('shop.index') }}" class="btn-ghost">{{ __('shop.order.continue_shopping') }}</a>
            @auth('client')<a href="{{ route('client.account', 'orders') }}" class="btn-ghost">{{ __('shop.account.title') }}</a>@endauth
        </div>
    </section>
</x-shop.shell>
