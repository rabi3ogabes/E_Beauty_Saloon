@php
    use App\Domain\Websites\Support\SiteSeo;
@endphp
<x-layouts.site :title="$page?->getTranslation('seo_title', app()->getLocale(), false) ?: null" :description="$page?->getTranslation('seo_description', app()->getLocale(), false) ?: null">
    @push('head')
        {!! SiteSeo::jsonLd(SiteSeo::organization($tenant)) !!}
        @if ($tenant->brand('hero_path'))
            <link rel="preload" as="image" href="{{ asset('storage/'.$tenant->brand('hero_path')) }}" fetchpriority="high">
        @endif
    @endpush

    @forelse ($sections as $i => $section)
        @include('site.website.sections.'.$section->type, ['section' => $section, 'data' => $data, 'tenant' => $tenant, 'first' => $i === 0])
    @empty
        <section class="container-luxe site-section text-center">
            <p class="eyebrow">{{ __('site.home.eyebrow') }}</p>
            <h1 class="hero-title mt-6 font-semibold">{{ $tenant->name }}</h1>
            <p class="mx-auto mt-6 max-w-xl text-lg text-muted">{{ __('website.empty.home') }}</p>
            @if ($tenant->hasModule('booking'))<a href="{{ url('/book') }}" class="btn-primary mt-10">{{ __('site.cta.book_now') }}</a>@endif
        </section>
    @endforelse
</x-layouts.site>
