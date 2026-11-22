@if ($products->isEmpty())
    <div class="card flex flex-col items-center px-6 py-16 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-rose/50 text-xl text-gold-dark">✦</span>
        <h2 class="mt-5 text-2xl font-semibold">{{ __('shop.filters.no_results') }}</h2>
        <p class="mt-2 max-w-md text-sm text-muted">{{ __('shop.filters.no_results_hint') }}</p>
        <a href="{{ route('shop.index') }}" class="btn-ghost mt-6">{{ __('shop.filters.clear') }}</a>
    </div>
@else
    <div class="grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($products as $product)
            <x-shop.product-card :product="$product" :wishlisted="in_array($product->id, $wishlisted ?? [], true)" />
        @endforeach
    </div>
    @if ($products->hasPages())
        <div class="mt-12">{{ $products->onEachSide(1)->links() }}</div>
    @endif
@endif
