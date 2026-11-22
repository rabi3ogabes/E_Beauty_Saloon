{{-- Rendered only when the Packages module ships <x-packages.highlights /> (checked in SectionRegistry::available). --}}
<section class="site-section">
    <div class="container-luxe">
        <div class="max-w-2xl" data-reveal>
            <h2 class="text-3xl font-semibold sm:text-5xl">{{ $section->t('title', __('website.sections.packages')) }}</h2>
            @if ($section->t('subtitle'))<p class="mt-4 text-muted">{{ $section->t('subtitle') }}</p>@endif
        </div>
        <div class="mt-10"><x-dynamic-component component="packages.highlights" /></div>
    </div>
</section>
