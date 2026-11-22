<x-layouts.platform :title="$content['seo_title'] ?: __('platform.nav.pricing')" :description="$content['seo_description'] ?: __('platform.pricing.meta')" :og-image="$content['og_image']">
    <h1 class="sr-only">{{ __('platform.nav.pricing') }}</h1>
    @include('platform.partials.blocks', ['firstIsTitle' => false])
</x-layouts.platform>
