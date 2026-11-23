@php
    $image = $section->setting('image') ?: $tenant->brand('hero_path');
    $title = $section->t('title', $tenant->name);
    $subtitle = $section->t('subtitle') ?: \Illuminate\Support\Str::limit(strip_tags((string) $tenant->brand('about')), 200);
    $tag = $first ? 'h1' : 'h2';
@endphp
<section class="site-glow relative overflow-hidden">
    <div class="container-luxe grid items-center gap-12 pt-10 pb-16 md:grid-cols-[1.05fr_1fr] md:gap-16 md:pt-16 md:pb-24">
        <div>
            <p class="eyebrow site-eyebrow-line">{{ $section->t('eyebrow', __('site.home.eyebrow')) }}</p>
            <{{ $tag }} class="hero-title mt-6 font-semibold text-balance">{{ $title }}</{{ $tag }}>
            @if ($subtitle)
                <p class="mt-7 max-w-lg text-lg leading-relaxed text-muted">{{ $subtitle }}</p>
            @endif
            <div class="mt-10 flex flex-wrap gap-3">
                @if ($tenant->hasModule('booking'))
                    <a href="{{ url('/book') }}" class="btn-primary px-8">{{ $section->t('cta_label', __('site.cta.book_now')) }}</a>
                @endif
                <a href="#services" class="btn-ghost px-7">{{ __('site.cta.explore') }}</a>
            </div>
            @if ($data->branches()->isNotEmpty())
                <dl class="mt-12 flex flex-wrap gap-x-10 gap-y-4 text-sm">
                    <div><dt class="text-muted">{{ __('website.hero.locations') }}</dt><dd class="mt-1 font-display text-2xl">{{ $data->branches()->count() }}</dd></div>
                    @php($open = $data->branches()->first(fn ($b) => $b->isOpenNow()))
                    <div><dt class="text-muted">{{ __('website.hero.today') }}</dt>
                        <dd class="mt-1 flex items-center gap-2 font-medium"><span @class(['h-2 w-2 rounded-full', 'bg-success' => $open, 'bg-muted' => ! $open])></span>{{ $open ? __('site.home.open_now') : __('site.home.closed_now') }}</dd></div>
                </dl>
            @endif
        </div>
        <div class="relative mx-auto w-full max-w-md md:max-w-none">
            <div class="hero-media">
                @if ($image)
                    <x-site.picture :path="$image" :alt="$tenant->name" :eager="$first" sizes="(min-width: 768px) 45vw, 90vw" width="800" height="1000" />
                @else
                    <div class="flex h-full w-full items-center justify-center">
                        <span class="font-display text-[9rem] leading-none text-white/70 select-none" aria-hidden="true">{{ mb_substr($tenant->name, 0, 1) }}</span>
                    </div>
                @endif
            </div>
            @if ($tenant->hasModule('ai_studio'))
                <a href="{{ url('/studio') }}" class="glass absolute -bottom-5 start-4 flex items-center gap-3 rounded-2xl px-5 py-4 shadow-soft transition hover:-translate-y-0.5 sm:start-8">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gold/20 text-gold-dark" aria-hidden="true">✦</span>
                    <span class="text-sm font-medium">{{ __('site.cta.try_studio') }}</span>
                </a>
            @endif
        </div>
    </div>
</section>
