<section class="site-section">
    <div class="container-luxe">
        <h2 class="text-center text-3xl font-semibold sm:text-5xl" data-reveal>{{ $block->t('title') }}</h2>
        <div class="mt-12 grid gap-6 md:grid-cols-3">
            @foreach ($block->list('items') as $item)
                <figure class="card flex flex-col p-7" data-reveal>
                    <span class="font-display text-5xl leading-none text-gold" aria-hidden="true">“</span>
                    <blockquote class="mt-2 flex-1 text-lg leading-relaxed">{{ $item['quote'] ?? '' }}</blockquote>
                    <figcaption class="mt-6 text-sm"><span class="font-semibold">{{ $item['name'] ?? '' }}</span><span class="block text-muted">{{ $item['role'] ?? '' }}</span></figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>
