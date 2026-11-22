<section class="site-section">
    <div class="container-luxe">
        <h2 class="mx-auto max-w-2xl text-center text-3xl font-semibold sm:text-5xl" data-reveal>{{ $block->t('title') }}</h2>
        @if ($block->t('subtitle'))<p class="mx-auto mt-4 max-w-2xl text-center text-muted">{{ $block->t('subtitle') }}</p>@endif
        <div class="mt-14 grid gap-x-10 gap-y-12 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($block->list('items') as $item)
                <div data-reveal>
                    <span class="site-ornament"></span>
                    <h3 class="mt-4 text-xl font-semibold">{{ $item['title'] ?? '' }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted">{{ $item['body'] ?? '' }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>
