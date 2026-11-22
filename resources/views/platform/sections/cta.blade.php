<section class="site-section">
    <div class="container-luxe">
        <div class="relative overflow-hidden rounded-(--radius-hero) bg-gradient-to-br from-rose via-ivory to-gold/30 px-6 py-16 text-center sm:py-20" data-reveal>
            <h2 class="mx-auto max-w-2xl text-3xl font-semibold sm:text-5xl">{{ $block->t('title') }}</h2>
            @if ($block->t('body'))<p class="mx-auto mt-4 max-w-xl text-muted">{{ $block->t('body') }}</p>@endif
            @if ($block->t('cta_label'))<a href="{{ url($block->t('cta_url', '/signup')) }}" class="btn-primary mt-9 px-9">{{ $block->t('cta_label') }}</a>@endif
        </div>
    </div>
</section>
