{{-- Rendered only when the Marketing module ships <x-marketing.reviews /> (checked in SectionRegistry::available). --}}
<section class="site-section">
    <div class="container-luxe">
        <div class="max-w-2xl" data-reveal>
            <p class="eyebrow">{{ __('website.labels.reviews') }}</p>
            <h2 class="mt-3 text-3xl font-semibold sm:text-5xl">{{ $section->t('title', __('website.sections.reviews')) }}</h2>
        </div>
        <div class="mt-10"><x-dynamic-component component="marketing.reviews" :limit="6" /></div>
    </div>
</section>
