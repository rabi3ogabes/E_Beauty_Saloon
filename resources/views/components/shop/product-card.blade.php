@props(['product', 'wishlisted' => false])
@php
    use App\Domain\Shop\Support\ProductCatalog;
    $variants = $product->variants;
    $default = $variants->sortByDesc('is_default')->first();
    $min = ProductCatalog::minPrice($product);
    $regular = (int) ($variants->min('price') ?? 0);
    $onSale = $variants->contains(fn ($v) => $v->sale_price !== null);
    $image = ProductCatalog::image($product);
    $swatches = $variants->pluck('swatch')->filter()->take(5);
@endphp
<article class="group relative" data-reveal>
    <a href="{{ route('shop.product', $product->slug) }}" class="block">
        <div class="relative aspect-[4/5] overflow-hidden rounded-(--radius-card) bg-gradient-to-br from-rose/70 via-ivory to-gold/20">
            @if ($image)
                <img src="{{ $image }}" alt="{{ $product->name }}" loading="lazy" decoding="async" class="h-full w-full object-cover transition duration-500 ease-(--ease-luxe) group-hover:scale-[1.04]">
            @else
                <span class="absolute inset-0 flex items-center justify-center font-display text-5xl text-gold-dark/40" aria-hidden="true">{{ mb_substr($product->brand?->name ?? $product->name, 0, 1) }}</span>
            @endif
            @if ($onSale)
                <span class="absolute start-3 top-3 rounded-full bg-ink px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-ivory">{{ __('shop.product.sale') }}</span>
            @endif
        </div>
        <div class="mt-3 space-y-0.5 px-0.5">
            @if ($product->brand)<p class="eyebrow !text-[10px]">{{ $product->brand->name }}</p>@endif
            <h3 class="line-clamp-2 font-sans text-sm font-medium leading-snug tracking-normal">{{ $product->name }}</h3>
            <p class="text-sm">
                @if ($variants->count() > 1 && $variants->unique(fn ($v) => $v->effectivePrice())->count() > 1)<span class="text-muted">{{ __('shop.product.from') }}</span>@endif
                <span class="{{ $onSale ? 'text-danger' : '' }}">{{ money($min) }}</span>
                @if ($onSale && $regular > $min)<s class="ms-1 text-xs text-muted">{{ money($regular) }}</s>@endif
            </p>
            @if ($swatches->count() > 1)
                <div class="flex gap-1 pt-1" aria-hidden="true">@foreach ($swatches as $s)<span class="h-3 w-3 rounded-full ring-1 ring-line" style="background: {{ e($s) }}"></span>@endforeach</div>
            @endif
        </div>
    </a>
    <div class="absolute end-3 top-3 flex flex-col gap-2">
        <button type="button" x-data="wishlistButton({{ $product->id }}, @js($wishlisted))" @click="toggle()" :disabled="busy"
                class="flex h-10 w-10 items-center justify-center rounded-full bg-white/85 shadow-soft backdrop-blur transition hover:scale-105" :aria-pressed="on"
                aria-label="{{ __('shop.product.wishlist_add') }}">
            <svg class="h-5 w-5 transition" :class="on ? 'fill-danger text-danger' : 'fill-transparent text-ink'" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"/></svg>
        </button>
        @if ($default && $variants->count() === 1)
            <button type="button" @click="$store.cart.add({{ $default->id }})" class="flex h-10 w-10 items-center justify-center rounded-full bg-ink text-ivory opacity-100 shadow-soft transition hover:scale-105 sm:opacity-0 sm:group-hover:opacity-100 focus:opacity-100" aria-label="{{ __('shop.product.add_to_cart') }}: {{ $product->name }}">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
            </button>
        @endif
    </div>
</article>
