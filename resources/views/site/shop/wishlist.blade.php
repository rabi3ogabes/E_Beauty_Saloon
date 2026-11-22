<x-shop.shell :title="__('shop.wishlist.title')">
    @push('head')<meta name="robots" content="noindex">@endpush
    <section class="container-luxe py-12">
        <p class="eyebrow">{{ __('shop.eyebrow') }}</p>
        <h1 class="mt-2 text-3xl font-semibold sm:text-4xl">{{ __('shop.wishlist.title') }}</h1>
        @if ($products->isEmpty())
            <div class="card mt-8 flex flex-col items-center px-6 py-16 text-center">
                <span class="flex h-16 w-16 items-center justify-center rounded-full bg-rose/50 text-2xl text-danger">♡</span>
                <p class="mt-5 font-display text-2xl">{{ __('shop.wishlist.empty') }}</p>
                <p class="mt-2 text-sm text-muted">{{ __('shop.wishlist.empty_sub') }}</p>
                <a href="{{ route('shop.index') }}" class="btn-primary mt-6">{{ __('shop.cart.empty_cta') }}</a>
            </div>
        @else
            <div class="mt-8 grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($products as $product)<x-shop.product-card :product="$product" :wishlisted="true" />@endforeach
            </div>
        @endif
    </section>
</x-shop.shell>
