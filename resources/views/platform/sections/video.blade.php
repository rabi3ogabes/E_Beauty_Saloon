@php
    $url = (string) $block->get('video_url', '');
    $yt = preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([A-Za-z0-9_-]{6,15})#', $url, $m) ? $m[1] : null;
@endphp
@if ($url)
<section class="site-section">
    <div class="container-luxe max-w-4xl">
        @if ($block->t('title'))<h2 class="mb-8 text-center text-3xl font-semibold sm:text-4xl">{{ $block->t('title') }}</h2>@endif
        <div class="aspect-video overflow-hidden rounded-(--radius-hero) bg-ink shadow-lift">
            @if ($yt)
                {{-- Click-to-load: no third-party iframe until the visitor asks for it. --}}
                <button type="button" x-data @click="$el.outerHTML = `<iframe class='h-full w-full' src='https://www.youtube-nocookie.com/embed/{{ $yt }}?autoplay=1' allow='autoplay; encrypted-media' allowfullscreen title='{{ e($block->t('title', 'Video')) }}'></iframe>`"
                        class="group flex h-full w-full items-center justify-center bg-cover bg-center" style="background-image: url('https://i.ytimg.com/vi/{{ $yt }}/hqdefault.jpg')" aria-label="Play video">
                    <span class="flex h-20 w-20 items-center justify-center rounded-full bg-white/90 text-2xl shadow-lift transition group-hover:scale-105">▶</span>
                </button>
            @else
                <video src="{{ $url }}" controls preload="none" class="h-full w-full"></video>
            @endif
        </div>
    </div>
</section>
@endif
