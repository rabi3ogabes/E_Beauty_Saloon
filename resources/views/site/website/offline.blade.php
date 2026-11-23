<x-layouts.site :title="__('website.pwa.offline_title')" :noindex="true">
    <section class="container-luxe site-section text-center">
        <p class="font-display text-6xl text-gold" aria-hidden="true">✦</p>
        <h1 class="mt-6 text-4xl font-semibold">{{ __('website.pwa.offline_title') }}</h1>
        <p class="mx-auto mt-4 max-w-md text-muted">{{ __('website.pwa.offline_body') }}</p>
        <button type="button" onclick="location.reload()" class="btn-primary mt-8">{{ __('website.pwa.retry') }}</button>
    </section>
</x-layouts.site>
