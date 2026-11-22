@php($items = collect($block->list('items'))->filter(fn ($i) => filled($i['q'] ?? null))->values())
<section class="site-section">
    <div class="container-luxe grid gap-10 md:grid-cols-[1fr_1.6fr]">
        <div><p class="eyebrow">FAQ</p><h2 class="mt-3 text-3xl font-semibold sm:text-4xl">{{ $block->t('title') }}</h2></div>
        <div class="divide-y hairline border-y hairline">
            @foreach ($items as $item)
                <details class="group py-5">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-6 text-lg font-medium">{{ $item['q'] }}<span class="text-gold-dark transition group-open:rotate-45" aria-hidden="true">+</span></summary>
                    <p class="mt-3 text-muted">{{ $item['a'] ?? '' }}</p>
                </details>
            @endforeach
        </div>
    </div>
    @push('head')
        {!! \App\Domain\Websites\Support\SiteSeo::jsonLd(\App\Domain\Websites\Support\SiteSeo::faq($items)) !!}
    @endpush
</section>
