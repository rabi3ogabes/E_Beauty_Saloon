<section class="site-section">
    <div class="container-luxe max-w-3xl" data-reveal>
        @if ($section->t('eyebrow'))<p class="eyebrow">{{ $section->t('eyebrow') }}</p>@endif
        @if ($section->t('title'))<h2 class="mt-3 text-3xl font-semibold sm:text-4xl">{{ $section->t('title') }}</h2>@endif
        @if ($section->t('body'))<div class="mt-5 space-y-4 text-lg leading-relaxed text-muted">{!! nl2br(e($section->t('body'))) !!}</div>@endif
    </div>
</section>
