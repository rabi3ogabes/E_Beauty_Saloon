@php($points = is_array($points) ? $points : [])
<x-layouts.platform :title="$content['seo_title'] ?: $module->name" :description="$content['seo_description'] ?: $module->description" :og-image="$content['og_image']">
    @if ($content['page'])
        @include('platform.partials.blocks')
    @else
        <section class="relative overflow-hidden">
            <div class="pointer-events-none absolute inset-x-0 -top-40 -z-10 mx-auto h-[30rem] max-w-4xl rounded-full opacity-50 blur-3xl" style="background: radial-gradient(closest-side, rgba(200,169,106,.35), transparent)" aria-hidden="true"></div>
            <div class="container-luxe pt-16 pb-10 text-center md:pt-24">
                <p class="eyebrow">{{ __('platform.features.eyebrow') }}</p>
                <h1 class="mx-auto mt-5 max-w-3xl text-[clamp(2.4rem,5.5vw,4.6rem)] leading-[1.04] font-semibold text-balance">{{ $module->name }}</h1>
                <p class="mx-auto mt-6 max-w-2xl text-lg text-muted">{{ $module->description }}</p>
                <div class="mt-9 flex flex-wrap justify-center gap-3">
                    <a href="{{ url('/signup') }}" class="btn-primary px-8">{{ __('platform.cta.start_trial') }}</a>
                    <a href="{{ url('/pricing') }}" class="btn-ghost">{{ __('platform.cta.see_pricing') }}</a>
                </div>
            </div>
        </section>
        @if ($points)
            <section class="site-section">
                <div class="container-luxe grid gap-x-10 gap-y-12 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($points as $point)
                        <div data-reveal><span class="site-ornament"></span><h2 class="mt-4 text-xl font-semibold">{{ $point['title'] }}</h2><p class="mt-2 text-sm leading-relaxed text-muted">{{ $point['body'] }}</p></div>
                    @endforeach
                </div>
            </section>
        @endif
        @if ($module->key === 'ai_studio')
            @include('platform.sections.studio_demo', ['block' => new \App\Domain\Websites\Support\Block(\App\Domain\Websites\Support\PlatformContent::defaults()['home']['sections'][3]), 'first' => false])
        @endif
        @include('platform.sections.cta', ['block' => new \App\Domain\Websites\Support\Block(\App\Domain\Websites\Support\PlatformContent::defaults()['home']['sections'][6]), 'first' => false])
    @endif

    <section class="container-luxe pb-6">
        <p class="text-sm text-muted">{{ __('platform.features.other') }}
            @foreach ($modules->where('key', '!=', $module->key) as $other)<a href="{{ url('/features/'.$other->key) }}" class="ms-3 font-medium text-gold-dark">{{ $other->name }}</a>@endforeach
        </p>
    </section>
</x-layouts.platform>
