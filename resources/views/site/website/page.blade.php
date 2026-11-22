<x-layouts.site :title="$page->getTranslation('seo_title', app()->getLocale(), false) ?: $page->title" :description="$page->getTranslation('seo_description', app()->getLocale(), false) ?: null"
                :og-image="$page->og_image_path ? asset('storage/'.$page->og_image_path) : null">
    @if ($sections->isEmpty() || $sections->first()->type !== 'hero')
        <section class="container-luxe pt-14 pb-4"><h1 class="hero-title font-semibold">{{ $page->title }}</h1></section>
    @endif
    @foreach ($sections as $i => $section)
        @include('site.website.sections.'.$section->type, ['section' => $section, 'data' => $data, 'tenant' => $tenant, 'first' => $i === 0])
    @endforeach
</x-layouts.site>
