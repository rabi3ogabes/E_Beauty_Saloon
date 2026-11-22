@php($styles = $data->studioStyles(6))
<section class="site-section">
    <div class="container-luxe">
        <div class="relative overflow-hidden rounded-(--radius-hero) bg-[#1E1620] px-6 py-14 text-[#F5EFE6] sm:px-14 sm:py-20" data-reveal>
            <div class="pointer-events-none absolute -end-24 -top-24 h-80 w-80 rounded-full opacity-40 blur-3xl" style="background: var(--brand-primary)" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -bottom-28 start-1/3 h-72 w-72 rounded-full opacity-25 blur-3xl" style="background: var(--brand-accent)" aria-hidden="true"></div>
            <div class="relative grid items-center gap-10 md:grid-cols-[1.2fr_1fr]">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.22em]" style="color: var(--brand-primary)">{{ $section->t('eyebrow', __('ai_studio.eyebrow')) }}</p>
                    <h2 class="mt-4 text-3xl font-semibold sm:text-5xl">{{ $section->t('title', __('ai_studio.headline')) }}</h2>
                    <p class="mt-5 max-w-lg text-[#F5EFE6]/70">{{ $section->t('body', __('ai_studio.subhead')) }}</p>
                    <a href="{{ url('/studio') }}" class="btn-gold mt-9 px-8">✦ {{ $section->t('cta_label', __('site.cta.try_studio')) }}</a>
                </div>
                @if ($styles->isNotEmpty())
                    <ul class="flex flex-wrap gap-2" aria-label="{{ __('ai_studio.title') }}">
                        @foreach ($styles as $style)
                            <li class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm backdrop-blur">
                                @if ($style->swatch)<span class="h-3 w-3 rounded-full" style="background: {{ \App\Domain\Websites\Support\SiteSeo::cssColor($style->swatch, '#C8A96A') }}"></span>@endif
                                {{ $style->name }}
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</section>
