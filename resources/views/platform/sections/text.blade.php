@php($body = (string) $block->t('body'))
<section class="site-section">
    <article class="container-luxe max-w-3xl">
        @if ($block->t('title'))<h2 class="text-3xl font-semibold sm:text-4xl">{{ $block->t('title') }}</h2>@endif
        <div class="mt-6 space-y-5 text-lg leading-relaxed text-ink/80 [&_a]:text-gold-dark [&_a]:underline [&_h3]:mt-8 [&_h3]:text-xl [&_li]:ms-5 [&_ul]:list-disc">
            @if ($body !== strip_tags($body))
                {!! safe_html($body, '<p><br><strong><em><b><i><ul><ol><li><a><h3><h4><blockquote>') !!}
            @else
                @foreach (preg_split("/\n{2,}/", $body) as $para)<p>{!! nl2br(e($para)) !!}</p>@endforeach
            @endif
        </div>
    </article>
</section>
