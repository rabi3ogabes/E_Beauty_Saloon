@php
    use App\Domain\Shop\Support\ProductCatalog;
    $images = collect($product->images ?? [])->keys()->map(fn ($i) => ProductCatalog::image($product, $i))->filter()->values();
    $track = $product->track_stock;
    $variantData = $product->variants->map(fn ($v) => [
        'id' => $v->id,
        'name' => $v->getTranslation('name', app()->getLocale(), false) ?: $v->sku,
        'swatch' => $v->swatch,
        'sku' => $v->sku,
        'price' => money($v->effectivePrice()),
        'regular' => $v->sale_price ? money((int) $v->price) : null,
        'stock' => $track ? ($availability[$v->id] ?? []) : $branches->mapWithKeys(fn ($b) => [$b->id => 99])->all(),
        'image' => null,
    ])->values();
    $selected = (int) request('variant');
    if ($selected && $variantData->firstWhere('id', $selected)) {
        $variantData = $variantData->sortByDesc(fn ($v) => $v['id'] === $selected)->values();
    }
    $hasShades = $product->variants->whereNotNull('swatch')->isNotEmpty();
    $video = $product->video_url;
    $embed = $video && preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([\w-]{11})~', $video, $m) ? 'https://www.youtube-nocookie.com/embed/'.$m[1] : null;
    $ld = [
        '@context' => 'https://schema.org', '@type' => 'Product',
        'name' => $product->name, 'description' => strip_tags((string) $product->description),
        'image' => $images->all(), 'sku' => $product->variants->first()?->sku,
        'brand' => $product->brand ? ['@type' => 'Brand', 'name' => $product->brand->name] : null,
        'category' => $product->category?->name,
        'offers' => $product->variants->map(fn ($v) => [
            '@type' => 'Offer', 'sku' => $v->sku, 'price' => \App\Support\Money\Money::of($v->effectivePrice(), tenant()->currency ?? 'QAR')->toDecimal(),
            'priceCurrency' => tenant()->currency ?? 'QAR', 'url' => route('shop.product', $product->slug).'?variant='.$v->id,
            'availability' => (! $track || collect($availability[$v->id] ?? [])->sum() > 0) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        ])->values()->all(),
    ];
    if ($reviewsCount) {
        $ld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $rating, 'reviewCount' => $reviewsCount];
    }
@endphp
<x-shop.shell :title="$product->name.($product->brand ? ' · '.$product->brand->name : '')" :description="\Illuminate\Support\Str::limit(strip_tags((string) $product->description), 155)">
    @push('head')
        <meta property="og:type" content="product">
        @if ($images->first())<meta property="og:image" content="{{ $images->first() }}">@endif
        <meta property="product:price:amount" content="{{ \App\Support\Money\Money::of(ProductCatalog::minPrice($product), tenant()->currency ?? 'QAR')->toDecimal() }}">
        <meta property="product:price:currency" content="{{ tenant()->currency ?? 'QAR' }}">
        <script type="application/ld+json">{!! json_encode(array_filter($ld), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endpush

    <div class="container-luxe py-8 sm:py-12" x-data="shopProduct(@js(['variants' => $variantData, 'images' => $images, 'branches' => $branches->mapWithKeys(fn ($b) => [$b->id => $b->name])]))">
        <nav class="mb-6 text-xs text-muted" aria-label="Breadcrumb">
            <a href="{{ route('shop.index') }}" class="hover:text-ink">{{ __('shop.title') }}</a>
            @if ($product->category)<span class="mx-1.5">/</span><a href="{{ route('shop.category', $product->category->slug) }}" class="hover:text-ink">{{ $product->category->name }}</a>@endif
        </nav>

        <div class="grid gap-10 lg:grid-cols-[1.1fr_1fr] lg:gap-16">
            {{-- Gallery --}}
            <section aria-label="{{ __('shop.product.gallery') }}">
                <div class="relative aspect-[4/5] overflow-hidden rounded-(--radius-hero) bg-gradient-to-br from-rose/70 via-ivory to-gold/30"
                     @mouseenter="zoom = images.length > 0" @mouseleave="zoom = false" @mousemove="move($event)">
                    <template x-if="images.length">
                        <img :src="images[image]" alt="{{ $product->name }}" class="h-full w-full object-cover transition-transform duration-200 ease-out"
                             :style="zoom ? `transform: scale(1.8); transform-origin: ${zx}% ${zy}%` : ''" fetchpriority="high">
                    </template>
                    <template x-if="!images.length">
                        <span class="absolute inset-0 flex items-center justify-center font-display text-8xl text-gold-dark/30" aria-hidden="true">{{ mb_substr($product->brand?->name ?? $product->name, 0, 1) }}</span>
                    </template>
                    <span x-show="variant?.swatch" class="absolute bottom-4 start-4 flex items-center gap-2 rounded-full bg-white/85 px-3 py-1.5 text-xs shadow-soft backdrop-blur">
                        <span class="h-4 w-4 rounded-full ring-1 ring-line" :style="`background:${variant?.swatch}`"></span><span x-text="variant?.name"></span>
                    </span>
                </div>
                @if ($images->count() > 1)
                    <div class="relative mt-3 flex gap-3 overflow-x-auto">
                        @foreach ($images as $i => $src)
                            <button type="button" class="h-20 w-16 shrink-0 overflow-hidden rounded-xl ring-2 transition" :class="image === {{ $i }} ? 'ring-gold' : 'ring-transparent'" @click="image = {{ $i }}" aria-label="{{ __('shop.product.gallery') }} {{ $i + 1 }}">
                                <img src="{{ $src }}" alt="" class="h-full w-full object-cover" loading="lazy">
                            </button>
                        @endforeach
                    </div>
                @endif
                @if ($video)
                    <div class="mt-6">
                        @if ($embed)
                            <div class="aspect-video overflow-hidden rounded-(--radius-card) bg-ink/5"><iframe src="{{ $embed }}" title="{{ __('shop.product.video') }}" class="h-full w-full" loading="lazy" allow="accelerometer; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>
                        @else
                            <a href="{{ $video }}" target="_blank" rel="noopener" class="btn-ghost">▶ {{ __('shop.product.video') }}</a>
                        @endif
                    </div>
                @endif
            </section>

            {{-- Buy box --}}
            <section class="lg:sticky lg:top-24 lg:self-start">
                @if ($product->brand)<a href="{{ route('shop.brand', $product->brand->slug) }}" class="eyebrow hover:underline">{{ $product->brand->name }}</a>@endif
                <h1 class="mt-2 text-3xl leading-tight font-semibold sm:text-4xl">{{ $product->name }}</h1>
                @if ($reviewsCount)
                    <a href="#reviews" class="mt-2 inline-flex items-center gap-1.5 text-sm text-muted"><span class="text-gold">★</span> {{ $rating }} · {{ __('shop.product.reviews_count', ['count' => $reviewsCount]) }}</a>
                @endif
                <p class="mt-4 flex items-baseline gap-2 text-2xl">
                    <span x-text="variant?.price" :class="variant?.regular ? 'text-danger' : ''">{{ $variantData->first()['price'] ?? '' }}</span>
                    <s x-show="variant?.regular" x-text="variant?.regular" class="text-base text-muted"></s>
                </p>

                @if ($product->variants->count() > 1)
                    <fieldset class="mt-6">
                        <legend class="label">{{ $hasShades ? __('shop.product.shade') : __('shop.product.size') }}: <span class="text-ink dark:text-ivory" x-text="variant?.name"></span></legend>
                        <div class="mt-2 flex flex-wrap gap-2.5">
                            @foreach ($variantData as $v)
                                @if ($hasShades && $v['swatch'])
                                    <button type="button" @click="select({{ $v['id'] }})" class="relative h-11 w-11 rounded-full ring-1 ring-line ring-offset-2 ring-offset-ivory transition hover:scale-105"
                                            :class="selected === {{ $v['id'] }} ? '!ring-2 !ring-ink' : ''" style="background: {{ e($v['swatch']) }}" :aria-pressed="selected === {{ $v['id'] }}" aria-label="{{ $v['name'] }}" title="{{ $v['name'] }}">
                                        @if (collect($v['stock'])->sum() <= 0)<span class="absolute inset-0 flex items-center justify-center text-ivory/90" aria-hidden="true">╱</span>@endif
                                    </button>
                                @else
                                    <button type="button" @click="select({{ $v['id'] }})" class="chip min-h-11" :class="selected === {{ $v['id'] }} ? 'chip-active' : ''" :aria-pressed="selected === {{ $v['id'] }}">{{ $v['name'] }}</button>
                                @endif
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                <div class="mt-6 flex items-center gap-3">
                    <div class="flex items-center rounded-full border hairline bg-white dark:bg-white/5" role="group" aria-label="{{ __('shop.product.qty') }}">
                        <button type="button" class="flex h-12 w-11 items-center justify-center" @click="qty = Math.max(1, qty - 1)" aria-label="{{ __('shop.cart.decrease') }}">−</button>
                        <span class="w-6 text-center" x-text="qty">1</span>
                        <button type="button" class="flex h-12 w-11 items-center justify-center" @click="qty = Math.min(available || 1, qty + 1)" aria-label="{{ __('shop.cart.increase') }}">+</button>
                    </div>
                    <button type="button" class="btn-primary h-12 flex-1" @click="add()" :disabled="adding || available < 1">
                        <span x-show="available >= 1 && !adding">{{ __('shop.product.add_to_cart') }}</span>
                        <span x-show="adding" x-cloak>{{ __('shop.product.adding') }}</span>
                        <span x-show="available < 1" x-cloak>{{ __('shop.product.sold_out') }}</span>
                    </button>
                    <button type="button" x-data="wishlistButton({{ $product->id }}, @js($wishlisted))" @click="toggle()" class="flex h-12 w-12 items-center justify-center rounded-full border hairline bg-white transition hover:border-gold dark:bg-white/5" :aria-pressed="on" aria-label="{{ __('shop.product.wishlist_add') }}">
                        <svg class="h-5 w-5" :class="on ? 'fill-danger text-danger' : 'fill-transparent'" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"/></svg>
                    </button>
                </div>
                <p class="mt-2 text-sm" x-show="available > 0 && available <= {{ $lowStockBadge }}" x-cloak><span class="text-warning">●</span> <span x-text="@js(__('shop.product.only_left', ['count' => '__N__'])).replace('__N__', available)"></span></p>
                <p class="mt-4 text-xs text-muted">{{ __('shop.product.delivery_note') }}</p>

                {{-- Availability per branch --}}
                @if ($track && $branches->count() > 0)
                    <div class="card mt-6 p-5">
                        <p class="label !mb-3">{{ __('shop.product.availability') }}</p>
                        <ul class="space-y-2 text-sm">
                            @foreach ($branches as $b)
                                <li class="flex items-center justify-between">
                                    <span>{{ $b->name }}</span>
                                    <span x-text="(variant?.stock?.[{{ $b->id }}] ?? 0) > 0 ? @js(__('shop.product.available_qty', ['count' => '__N__'])).replace('__N__', variant.stock[{{ $b->id }}]) : @js(__('shop.product.unavailable'))"
                                          :class="(variant?.stock?.[{{ $b->id }}] ?? 0) > 0 ? 'text-success' : 'text-muted'"></span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($product->description)
                    <div class="mt-8 border-t hairline pt-6">
                        <h2 class="font-display text-lg">{{ __('shop.product.description') }}</h2>
                        <div class="prose-sm mt-2 leading-relaxed text-muted">{!! nl2br(e(strip_tags((string) $product->description))) !!}</div>
                        <p class="mt-3 text-xs text-muted">{{ __('shop.product.sku') }}: <span dir="ltr" x-text="variant?.sku"></span></p>
                    </div>
                @endif

                @if ($services->isNotEmpty())
                    <div class="mt-8 border-t hairline pt-6">
                        <h2 class="font-display text-lg">{{ __('shop.product.used_in_services') }}</h2>
                        <ul class="mt-3 space-y-2">
                            @foreach ($services as $service)
                                <li class="flex items-center justify-between gap-3 rounded-xl bg-white px-4 py-3 text-sm dark:bg-white/5">
                                    <span><span class="font-medium">{{ $service->name }}</span> <span class="text-muted">· {{ $service->duration_minutes }} {{ __('salon.ui.minutes') }}</span></span>
                                    @if (tenant()->hasModule('booking'))<a href="{{ url('/book?service='.$service->slug) }}" class="text-gold-dark underline underline-offset-4">{{ __('shop.product.book_service') }}</a>@endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        </div>

        {{-- Recommended by your stylist --}}
        @if ($stylistPicks->isNotEmpty())
            <section class="mt-20 rounded-(--radius-hero) bg-gradient-to-br from-rose/60 via-ivory to-gold/20 p-6 sm:p-10 dark:from-plum dark:via-night dark:to-plum">
                <p class="eyebrow">✦ {{ __('shop.product.stylist_sub') }}</p>
                <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">{{ $stylist ? __('shop.product.stylist_title', ['name' => $stylist->name]) : __('shop.product.stylist_title_generic') }}</h2>
                <div class="mt-8 grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-4">
                    @foreach ($stylistPicks->take(4) as $p)<x-shop.product-card :product="$p" />@endforeach
                </div>
            </section>
        @endif

        {{-- Reviews --}}
        <section id="reviews" class="mt-20">
            <div class="flex items-end justify-between">
                <h2 class="text-2xl font-semibold sm:text-3xl">{{ __('shop.product.reviews') }}</h2>
                @if ($reviewsCount)<p class="text-sm text-muted"><span class="text-gold">★</span> {{ $rating }} · {{ __('shop.product.reviews_count', ['count' => $reviewsCount]) }}</p>@endif
            </div>
            @if ($reviews->isEmpty())
                <p class="card mt-6 p-6 text-sm text-muted">{{ __('shop.product.no_reviews') }}</p>
            @else
                <div class="mt-6 grid gap-4 md:grid-cols-2">
                    @foreach ($reviews as $review)
                        <article class="card p-6">
                            <p class="text-gold" aria-label="{{ $review->rating }}/5">{{ str_repeat('★', $review->rating) }}<span class="text-line">{{ str_repeat('★', 5 - $review->rating) }}</span></p>
                            @if ($review->title)<h3 class="mt-2 font-sans text-base font-semibold tracking-normal">{{ $review->title }}</h3>@endif
                            @if ($review->body)<p class="mt-1 text-sm text-muted">{{ $review->body }}</p>@endif
                            <p class="mt-3 text-xs text-muted">{{ $review->author_name }} · {{ $review->created_at->translatedFormat('M Y') }}</p>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($related->isNotEmpty())
            <section class="mt-20">
                <h2 class="text-2xl font-semibold sm:text-3xl">{{ __('shop.product.related') }}</h2>
                <div class="mt-8 grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-4">
                    @foreach ($related as $p)<x-shop.product-card :product="$p" />@endforeach
                </div>
            </section>
        @endif
    </div>
</x-shop.shell>
