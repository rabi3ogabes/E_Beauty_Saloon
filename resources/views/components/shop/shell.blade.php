@props(['title' => null, 'description' => null, 'search' => '', 'bar' => true])
@php
    $shopCategories = \App\Domain\Catalog\Models\ProductCategory::query()->whereNull('parent_id')->orderBy('sort')->limit(8)->get();
@endphp
<x-layouts.site :title="$title ?? __('shop.title')" :description="$description ?? __('shop.meta_description', ['salon' => tenant()?->name])">
    @push('head')
        @include('site.shop.partials.runtime')
        {{ $head ?? '' }}
    @endpush

    @if ($bar)
        <div class="border-y hairline bg-white/60 backdrop-blur dark:bg-plum/40">
            <div class="container-luxe flex items-center gap-3 py-3">
                <form action="{{ route('shop.index') }}" method="GET" role="search" class="relative flex-1" x-data="shopSearch(@js($search))" @click.outside="open = false">
                    <label for="shop-q" class="sr-only">{{ __('shop.nav.search') }}</label>
                    <svg class="pointer-events-none absolute start-4 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                    <input id="shop-q" name="q" type="search" x-model="q" @input.debounce.250ms="suggest()" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter="go($event)" @focus="items.length && (open = true)"
                           autocomplete="off" class="input rounded-full ps-11" placeholder="{{ __('shop.nav.search_placeholder') }}" value="{{ $search }}" role="combobox" :aria-expanded="open" aria-controls="shop-suggest">
                    <div id="shop-suggest" x-show="open" x-cloak x-transition.opacity.duration.150ms class="card absolute inset-x-0 top-full z-30 mt-2 overflow-hidden p-2" role="listbox">
                        <template x-if="loading"><div class="space-y-2 p-2"><div class="skeleton h-10"></div><div class="skeleton h-10"></div></div></template>
                        <template x-for="(item, i) in items" :key="item.url">
                            <a :href="item.url" class="flex items-center gap-3 rounded-xl px-3 py-2 transition" :class="active === i ? 'bg-ivory' : 'hover:bg-ivory'" role="option">
                                <span class="h-10 w-10 shrink-0 overflow-hidden rounded-lg bg-gradient-to-br from-rose to-ivory"><img x-show="item.image" :src="item.image" alt="" class="h-full w-full object-cover"></span>
                                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium" x-text="item.name"></span><span class="block text-xs text-muted" x-text="item.brand"></span></span>
                                <span class="text-sm" x-text="item.price"></span>
                            </a>
                        </template>
                        <p x-show="!loading && !items.length" class="px-3 py-3 text-sm text-muted">{{ __('shop.filters.no_results') }}</p>
                    </div>
                </form>
                <a href="{{ route('shop.wishlist') }}" class="chip min-h-11 px-3" aria-label="{{ __('shop.nav.wishlist') }}">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"/></svg>
                </a>
                <button type="button" class="btn-primary relative min-h-11 px-4" @click="$store.cart.show()" aria-label="{{ __('shop.cart.open') }}">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z"/></svg>
                    <span class="hidden sm:inline">{{ __('shop.nav.cart') }}</span>
                    <span x-show="$store.cart.data.count > 0" x-cloak x-text="$store.cart.data.count" class="absolute -end-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-gold px-1 text-[11px] font-semibold text-ink"></span>
                </button>
            </div>
            @if ($shopCategories->isNotEmpty())
                <nav class="relative container-luxe -mt-1 flex gap-2 overflow-x-auto pb-3 [scrollbar-width:none]" aria-label="{{ __('shop.nav.collections') }}">
                    <a href="{{ route('shop.index') }}" @class(['chip whitespace-nowrap', 'chip-active' => request()->routeIs('shop.index') && ! request('category')])>{{ __('shop.nav.all') }}</a>
                    @foreach ($shopCategories as $c)
                        <a href="{{ route('shop.category', $c->slug) }}" @class(['chip whitespace-nowrap', 'chip-active' => request()->route('slug') === $c->slug || request('category') === $c->slug])>{{ $c->name }}</a>
                    @endforeach
                </nav>
            @endif
        </div>
    @endif

    <div x-data x-init="$store.cart.load()">
        {{ $slot }}
    </div>

    @include('site.shop.partials.cart-drawer')
</x-layouts.site>
