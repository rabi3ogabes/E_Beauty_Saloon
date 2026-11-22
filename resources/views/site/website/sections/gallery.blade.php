@php
    $images = $data->gallery((array) $section->settings);
    $pairs = array_values(array_filter((array) $section->setting('before_after', []), fn ($p) => ! empty($p['before']) && ! empty($p['after'])));
@endphp
@if ($images || $pairs)
<section class="site-section">
    <div class="container-luxe">
        <div class="max-w-2xl" data-reveal>
            <p class="eyebrow">{{ __('website.labels.gallery') }}</p>
            <h2 class="mt-3 text-3xl font-semibold sm:text-5xl">{{ $section->t('title', __('website.sections.gallery')) }}</h2>
            @if ($section->t('subtitle'))<p class="mt-4 text-muted">{{ $section->t('subtitle') }}</p>@endif
        </div>

        @if ($pairs)
            <div class="mt-10 grid gap-6 md:grid-cols-2">
                @foreach ($pairs as $pair)
                    <div x-data="{ pos: 50 }" dir="ltr" class="relative aspect-[4/5] overflow-hidden rounded-(--radius-card) bg-line" data-reveal>
                        <x-site.picture :path="$pair['after']" :alt="__('ai_studio.look.after')" class="absolute inset-0 h-full w-full object-cover" sizes="(min-width: 768px) 45vw, 90vw" />
                        <x-site.picture :path="$pair['before']" :alt="__('ai_studio.look.before')" class="absolute inset-0 h-full w-full object-cover" ::style="`clip-path: inset(0 ${100 - pos}% 0 0)`" sizes="(min-width: 768px) 45vw, 90vw" />
                        <div class="pointer-events-none absolute inset-y-0 w-px bg-white/90" :style="`left: ${pos}%`"></div>
                        <input type="range" min="0" max="100" x-model="pos" class="absolute inset-x-6 bottom-5" aria-label="{{ __('ai_studio.look.compare') }}">
                    </div>
                @endforeach
            </div>
        @endif

        @if ($images)
            <div class="mt-10 columns-2 gap-4 md:columns-3 [&>*]:mb-4">
                @foreach (array_slice($images, 0, 9) as $img)
                    <x-site.picture :path="$img" :alt="$tenant->name" class="w-full break-inside-avoid rounded-(--radius-card) object-cover" sizes="(min-width: 768px) 30vw, 45vw" data-reveal />
                @endforeach
            </div>
        @endif
    </div>
</section>
@endif
