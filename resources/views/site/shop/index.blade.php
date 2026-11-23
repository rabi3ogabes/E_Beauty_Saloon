@php
    $heading = $category?->name ?? $brand?->name ?? (! empty($filters['q']) ? '“'.$filters['q'].'”' : __('shop.hero_title'));
    $action = $category ? route('shop.category', $category->slug) : ($brand ? route('shop.brand', $brand->slug) : route('shop.index'));
    $attrLabel = fn ($v) => \Illuminate\Support\Facades\Lang::has('shop.attr.'.$v) ? __('shop.attr.'.$v) : ucfirst($v);
@endphp
<x-shop.shell :title="$category?->name ?? $brand?->name ?? __('shop.title')" :search="$filters['q'] ?? ''">
    @push('head')
        <link rel="canonical" href="{{ $action }}">
        @if ($products->currentPage() > 1)<meta name="robots" content="noindex,follow">@endif
        <script type="application/ld+json">{!! json_encode([
            '@context' => 'https://schema.org', '@type' => 'ItemList',
            'itemListElement' => $products->values()->map(fn ($p, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'url' => route('shop.product', $p->slug), 'name' => $p->name])->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endpush

    <section class="container-luxe pt-10 pb-6 sm:pt-14">
        <p class="eyebrow">{{ $brand ? __('shop.nav.brands') : __('shop.eyebrow') }}</p>
        <h1 class="mt-3 max-w-3xl text-3xl leading-tight font-semibold sm:text-5xl">{{ $heading }}</h1>
        @if (! $category && ! $brand && empty($filters['q']))
            <p class="mt-4 max-w-2xl text-muted">{{ __('shop.hero_sub') }}</p>
        @endif
    </section>

    <section class="container-luxe pb-20" x-data="shopListing()">
        <form x-ref="form" action="{{ $action }}" method="GET" @change="submit($el)" @submit.prevent="submit($el)" class="grid gap-8 lg:grid-cols-[240px_1fr]">
            @if (! empty($filters['q']))<input type="hidden" name="q" value="{{ $filters['q'] }}">@endif

            {{-- Filters: sidebar on desktop, sheet on mobile --}}
            <div x-show="filtersOpen" x-cloak class="fixed inset-0 z-50 bg-ink/30 lg:hidden" @click="filtersOpen = false"></div>
            <aside :class="filtersOpen ? 'translate-y-0' : 'translate-y-full lg:translate-y-0'"
                   class="fixed inset-x-0 bottom-0 z-50 max-h-[85dvh] translate-y-full overflow-y-auto rounded-t-(--radius-hero) bg-ivory p-6 shadow-lift transition duration-250 ease-(--ease-luxe) lg:static lg:z-auto lg:max-h-none lg:translate-y-0 lg:overflow-visible lg:rounded-none lg:bg-transparent lg:p-0 lg:shadow-none dark:bg-night lg:dark:bg-transparent"
                   aria-label="{{ __('shop.filters.title') }}">
                <div class="mb-4 flex items-center justify-between lg:hidden">
                    <p class="font-display text-xl">{{ __('shop.filters.title') }}</p>
                    <button type="button" class="chip min-h-11" @click="filtersOpen = false">{{ __('shop.cart.close') }}</button>
                </div>
                <div class="space-y-7">
                    @if (! $category)
                        <fieldset>
                            <legend class="label">{{ __('shop.filters.category') }}</legend>
                            <select name="category" class="input">
                                <option value="">{{ __('shop.filters.any') }}</option>
                                @foreach ($facets['categories'] as $c)<option value="{{ $c->slug }}" @selected(($filters['category'] ?? '') === $c->slug)>{{ $c->name }}</option>@endforeach
                            </select>
                        </fieldset>
                    @endif
                    @if (! $brand && $facets['brands']->isNotEmpty())
                        <fieldset>
                            <legend class="label">{{ __('shop.filters.brand') }}</legend>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($facets['brands'] as $b)
                                    <label class="chip cursor-pointer has-checked:chip-active">
                                        <input type="checkbox" name="brand[]" value="{{ $b->slug }}" class="sr-only" @checked(in_array($b->slug, $filters['brand'] ?? [], true))>{{ $b->name }}
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endif
                    @foreach (['hair_type' => 'hair_types', 'skin_type' => 'skin_types'] as $attr => $facet)
                        @if ($facets[$facet]->isNotEmpty())
                            <fieldset>
                                <legend class="label">{{ __('shop.filters.'.$attr) }}</legend>
                                <div class="flex flex-wrap gap-2">
                                    <label class="chip cursor-pointer has-checked:chip-active"><input type="radio" name="{{ $attr }}" value="" class="sr-only" @checked(empty($filters[$attr]))>{{ __('shop.filters.any') }}</label>
                                    @foreach ($facets[$facet] as $v)
                                        <label class="chip cursor-pointer has-checked:chip-active"><input type="radio" name="{{ $attr }}" value="{{ $v }}" class="sr-only" @checked(($filters[$attr] ?? '') === $v)>{{ $attrLabel($v) }}</label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endif
                    @endforeach
                    <fieldset>
                        <legend class="label">{{ __('shop.filters.price') }} ({{ tenant()->currency ?? 'QAR' }})</legend>
                        <div class="flex items-center gap-2">
                            <input type="number" name="min" min="0" inputmode="numeric" class="input" placeholder="{{ __('shop.filters.min') }}" value="{{ $filters['min'] ?? '' }}" aria-label="{{ __('shop.filters.min') }}">
                            <span class="text-muted">–</span>
                            <input type="number" name="max" min="0" inputmode="numeric" class="input" placeholder="{{ __('shop.filters.max') }}" value="{{ $filters['max'] ?? '' }}" aria-label="{{ __('shop.filters.max') }}">
                        </div>
                    </fieldset>
                    <div class="flex gap-2">
                        <button class="btn-primary flex-1 lg:hidden">{{ __('shop.filters.apply') }}</button>
                        <a href="{{ $action }}" class="btn-ghost flex-1">{{ __('shop.filters.clear') }}</a>
                    </div>
                </div>
            </aside>

            <div>
                <div class="mb-6 flex items-center justify-between gap-3">
                    <p class="text-sm text-muted" x-ref="count" data-template="{{ __('shop.filters.results', ['count' => ':count']) }}">{{ __('shop.filters.results', ['count' => $products->total()]) }}</p>
                    <div class="flex items-center gap-2">
                        <button type="button" class="chip min-h-11 lg:hidden" @click="filtersOpen = true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M4 6h16M7 12h10M10 18h4"/></svg>{{ __('shop.filters.title') }}
                        </button>
                        <label for="sort" class="sr-only">{{ __('shop.filters.sort') }}</label>
                        <select id="sort" name="sort" class="input !min-h-11 !w-auto rounded-full">
                            @foreach ($sorts as $s)<option value="{{ $s }}" @selected(($filters['sort'] ?? 'featured') === $s)>{{ __('shop.sort.'.$s) }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="relative">
                    <div x-show="loading" x-cloak class="absolute inset-0 z-10 grid grid-cols-2 gap-x-4 gap-y-8 bg-ivory sm:grid-cols-3 lg:grid-cols-4 dark:bg-night" aria-hidden="true">
                        @for ($i = 0; $i < 8; $i++)<div><div class="skeleton aspect-[4/5]"></div><div class="skeleton mt-3 h-3 w-1/3"></div><div class="skeleton mt-2 h-4 w-3/4"></div></div>@endfor
                    </div>
                    <div x-ref="grid" :aria-busy="loading">
                        @include('site.shop.partials.grid')
                    </div>
                </div>
            </div>
        </form>
    </section>
</x-shop.shell>
