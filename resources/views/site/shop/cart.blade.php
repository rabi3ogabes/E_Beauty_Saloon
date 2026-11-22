<x-shop.shell :title="__('shop.cart.title')">
    @push('head')<meta name="robots" content="noindex">@endpush
    <section class="container-luxe py-12">
        <h1 class="text-3xl font-semibold sm:text-4xl">{{ __('shop.cart.title') }}</h1>
        @if (session('status'))<p class="mt-4 rounded-xl bg-success/10 px-4 py-3 text-sm text-success" role="status">{{ session('status') }}</p>@endif
        @error('cart')<p class="mt-4 rounded-xl bg-danger/10 px-4 py-3 text-sm text-danger">{{ $message }}</p>@enderror

        @if (empty($cart['items']))
            <div class="card mt-8 flex flex-col items-center px-6 py-16 text-center">
                <span class="flex h-16 w-16 items-center justify-center rounded-full bg-rose/50 text-2xl text-gold-dark">✦</span>
                <p class="mt-5 font-display text-2xl">{{ __('shop.cart.empty') }}</p>
                <p class="mt-2 text-sm text-muted">{{ __('shop.cart.empty_sub') }}</p>
                <a href="{{ route('shop.index') }}" class="btn-primary mt-6">{{ __('shop.cart.empty_cta') }}</a>
            </div>
        @else
            <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_360px]">
                <ul class="card divide-y hairline px-6">
                    @foreach ($cart['items'] as $item)
                        <li class="flex gap-4 py-5">
                            <a href="{{ $item['url'] }}" class="h-24 w-24 shrink-0 overflow-hidden rounded-xl bg-gradient-to-br from-rose to-ivory">@if ($item['image'])<img src="{{ $item['image'] }}" alt="" class="h-full w-full object-cover">@endif</a>
                            <div class="flex-1">
                                <p class="eyebrow !text-[10px]">{{ $item['brand'] }}</p>
                                <a href="{{ $item['url'] }}" class="font-medium">{{ $item['name'] }}</a>
                                @if ($item['variant'])<p class="text-xs text-muted">{{ $item['variant'] }}</p>@endif
                                <div class="mt-3 flex items-center justify-between">
                                    <form method="POST" action="{{ route('shop.cart.update', $item['id']) }}" class="flex items-center gap-2">
                                        @csrf @method('PATCH')
                                        <label class="sr-only" for="qty-{{ $item['id'] }}">{{ __('shop.cart.qty') }}</label>
                                        <input id="qty-{{ $item['id'] }}" type="number" name="qty" min="0" max="99" value="{{ $item['qty'] }}" class="input !w-20" onchange="this.form.submit()">
                                    </form>
                                    <p class="font-medium">{{ $item['line_total_formatted'] }}</p>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <aside class="card h-fit p-6">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-muted">{{ __('shop.cart.subtotal') }}</dt><dd>{{ $cart['formatted']['subtotal'] }}</dd></div>
                        @if ($cart['discount'])<div class="flex justify-between text-success"><dt>{{ __('shop.cart.discount') }}</dt><dd>−{{ $cart['formatted']['discount'] }}</dd></div>@endif
                        @if ($cart['gift_wrap_fee'])<div class="flex justify-between"><dt class="text-muted">{{ __('shop.cart.gift_wrap') }}</dt><dd>{{ $cart['formatted']['gift_wrap'] }}</dd></div>@endif
                        <div class="flex justify-between border-t hairline pt-3 text-base font-semibold"><dt>{{ __('shop.cart.total') }}</dt><dd>{{ $cart['formatted']['total'] }}</dd></div>
                    </dl>
                    <form method="POST" action="{{ route('shop.cart.coupon') }}" class="mt-4 flex gap-2">
                        @csrf
                        <input name="code" class="input uppercase" placeholder="{{ __('shop.cart.coupon_placeholder') }}" value="{{ $cart['coupon']['code'] ?? '' }}" aria-label="{{ __('shop.cart.coupon') }}">
                        <button class="btn-ghost shrink-0">{{ __('shop.cart.apply') }}</button>
                    </form>
                    <a href="{{ route('shop.checkout') }}" class="btn-primary mt-5 w-full">{{ __('shop.cart.checkout') }}</a>
                </aside>
            </div>
        @endif
    </section>
</x-shop.shell>
