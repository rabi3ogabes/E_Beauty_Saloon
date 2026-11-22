<x-layouts.platform :title="$content['seo_title'] ?: $content['title']" :description="$content['seo_description']" :og-image="$content['og_image']">
    @if ($content['blocks']->first()?->type !== 'hero')
        <section class="container-luxe pt-16 md:pt-20">
            <p class="eyebrow">{{ config('salon.name') }}</p>
            <h1 class="mt-4 text-[clamp(2.2rem,5vw,4rem)] leading-[1.05] font-semibold">{{ $content['title'] }}</h1>
        </section>
    @endif
    @include('platform.partials.blocks', ['firstIsTitle' => $content['blocks']->first()?->type === 'hero'])
</x-layouts.platform>
