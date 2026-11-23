@php
    $handle = ltrim((string) ($section->setting('handle') ?: ''), '@');
    $urls = collect((array) $section->setting('urls', []))->filter(fn ($u) => is_string($u) && preg_match('#^https://(www\.)?instagram\.com/(p|reel)/[A-Za-z0-9_\-]+/?#', $u))->take(6)->values();
    $profile = $handle ? 'https://instagram.com/'.$handle : data_get($tenant->brand('social'), 'instagram');
@endphp
@if ($urls->isNotEmpty() || $profile)
<section class="site-section">
    <div class="container-luxe">
        <div class="flex flex-wrap items-end justify-between gap-6" data-reveal>
            <div class="max-w-2xl">
                <p class="eyebrow">Instagram</p>
                <h2 class="mt-3 text-3xl font-semibold sm:text-5xl">{{ $section->t('title', $handle ? '@'.$handle : __('website.sections.instagram')) }}</h2>
                @if ($section->t('subtitle'))<p class="mt-4 text-muted">{{ $section->t('subtitle') }}</p>@endif
            </div>
            @if ($profile)<a href="{{ $profile }}" target="_blank" rel="noopener" class="btn-ghost">{{ __('website.labels.follow') }}</a>@endif
        </div>
        @if ($urls->isNotEmpty())
            {{-- Official embeds, loaded only when the section scrolls into view. --}}
            <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3" data-instagram>
                @foreach ($urls as $url)
                    <blockquote class="instagram-media card m-0! min-w-0! w-full! p-6" data-instgrm-permalink="{{ $url }}" data-instgrm-version="14">
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="text-sm text-muted">{{ __('website.labels.view_post') }}</a>
                    </blockquote>
                @endforeach
            </div>
            @once
                @push('scripts')
                    <script>
                        (function () {
                            var el = document.querySelector('[data-instagram]'); if (!el || !('IntersectionObserver' in window)) return;
                            new IntersectionObserver(function (entries, io) {
                                if (!entries[0].isIntersecting) return; io.disconnect();
                                var s = document.createElement('script'); s.async = true; s.src = 'https://www.instagram.com/embed.js'; document.body.appendChild(s);
                            }, { rootMargin: '200px' }).observe(el);
                        })();
                    </script>
                @endpush
            @endonce
        @endif
    </div>
</section>
@endif
