@php
    $locale = app()->getLocale();
    $body = (string) $post->body;
@endphp
<x-layouts.platform :title="$post->getTranslation('seo_title', $locale, false) ?: $post->title" :description="$post->getTranslation('seo_description', $locale, false) ?: $post->excerpt"
                    :og-image="$post->cover_path ? asset('storage/'.$post->cover_path) : null">
    @push('head')
        {!! \App\Domain\Websites\Support\SiteSeo::jsonLd(\App\Domain\Websites\Support\SiteSeo::blogPosting($post)) !!}
    @endpush
    <article>
        <header class="container-luxe max-w-3xl pt-16 md:pt-20">
            <a href="{{ url('/blog') }}" class="text-sm text-muted hover:text-ink">← {{ __('platform.blog.all') }}</a>
            <p class="mt-8 text-xs text-muted">{{ $post->published_at?->translatedFormat('j F Y') }} · {{ $post->author_name }} · {{ trans_choice('platform.blog.minutes', $post->readingMinutes(), ['count' => $post->readingMinutes()]) }}</p>
            <h1 class="mt-3 text-[clamp(2.2rem,5vw,3.8rem)] leading-[1.06] font-semibold text-balance">{{ $post->title }}</h1>
            @if ($post->excerpt)<p class="mt-5 text-xl text-muted">{{ $post->excerpt }}</p>@endif
        </header>
        @if ($post->cover_path)
            <div class="container-luxe mt-10 max-w-4xl"><x-site.picture :path="$post->cover_path" :alt="$post->title" :eager="true" class="aspect-[16/9] w-full rounded-(--radius-hero) object-cover" sizes="(min-width: 1024px) 896px, 100vw" /></div>
        @endif
        <div class="container-luxe mt-10 max-w-3xl space-y-5 text-lg leading-relaxed text-ink/85 [&_a]:text-gold-dark [&_a]:underline [&_blockquote]:border-s-2 [&_blockquote]:border-gold [&_blockquote]:ps-5 [&_blockquote]:italic [&_h2]:mt-10 [&_h2]:text-2xl [&_h2]:font-semibold [&_h3]:mt-8 [&_h3]:text-xl [&_li]:ms-5 [&_ol]:list-decimal [&_ul]:list-disc">
            {!! strip_tags($body, '<p><br><strong><em><b><i><ul><ol><li><a><h2><h3><h4><blockquote><figure><img><figcaption>') !!}
        </div>
    </article>
    @if ($related->isNotEmpty())
        <section class="container-luxe site-section">
            <h2 class="text-2xl font-semibold">{{ __('platform.blog.related') }}</h2>
            <div class="mt-8 grid gap-6 md:grid-cols-3">@foreach ($related as $r) @include('platform.blog.card', ['post' => $r]) @endforeach</div>
        </section>
    @endif
</x-layouts.platform>
