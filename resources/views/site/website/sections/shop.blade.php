@php($products = $data->products((int) $section->setting('limit', 4)))
@if ($products->isNotEmpty())
<section class="site-section">
    <div class="container-luxe">
        <div class="flex flex-wrap items-end justify-between gap-6" data-reveal>
            <div class="max-w-2xl">
                <p class="eyebrow">{{ __('site.nav.shop') }}</p>
                <h2 class="mt-3 text-3xl font-semibold sm:text-5xl">{{ $section->t('title', __('website.sections.shop')) }}</h2>
                @if ($section->t('subtitle'))<p class="mt-4 text-muted">{{ $section->t('subtitle') }}</p>@endif
            </div>
            <a href="{{ url('/shop') }}" class="btn-ghost">{{ __('website.labels.shop_all') }}</a>
        </div>
        <div class="mt-10 grid grid-cols-2 gap-5 md:grid-cols-4">
            @foreach ($products as $product)
                <a href="{{ url('/shop/p/'.$product->slug) }}" class="card-hover group overflow-hidden" data-reveal>
                    <div class="aspect-square bg-gradient-to-br from-ivory to-rose/50">
                        @if ($img = data_get($product->images, 0))
                            <x-site.picture :path="$img" :alt="$product->name" class="h-full w-full object-cover" sizes="(min-width: 768px) 22vw, 45vw" />
                        @else
                            <div class="flex h-full items-center justify-center p-6 text-center font-display text-lg text-ink/40">{{ $product->name }}</div>
                        @endif
                    </div>
                    <div class="p-4">
                        <p class="text-xs text-muted">{{ $product->brand?->name }}</p>
                        <p class="mt-1 text-sm font-medium">{{ $product->name }}</p>
                        @if ($product->defaultVariant)<p class="mt-2 font-display">{{ money($product->defaultVariant->effectivePrice()) }}</p>@endif
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif
