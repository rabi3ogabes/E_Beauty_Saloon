@php($categories = $data->categories())
<section id="services" class="site-section">
    <div class="container-luxe">
        <div class="flex flex-wrap items-end justify-between gap-6" data-reveal>
            <div class="max-w-2xl">
                <p class="eyebrow">{{ __('website.labels.menu') }}</p>
                <h2 class="mt-3 text-3xl font-semibold sm:text-5xl">{{ $section->t('title', __('site.home.services_title')) }}</h2>
                @if ($section->t('subtitle'))<p class="mt-4 text-muted">{{ $section->t('subtitle') }}</p>@endif
            </div>
            @if ($tenant->hasModule('booking'))<a href="{{ url('/book') }}" class="btn-ghost">{{ __('site.cta.book_now') }}</a>@endif
        </div>

        @if ($categories->isEmpty())
            <div class="card mt-10 p-10 text-center text-muted">{{ __('website.empty.services') }}</div>
        @else
            <div x-data="{ tab: {{ $categories->first()->id }} }" class="mt-10">
                <div class="flex gap-2 overflow-x-auto pb-2" role="tablist" aria-label="{{ __('website.labels.categories') }}">
                    @foreach ($categories as $category)
                        <button type="button" role="tab" class="chip min-h-11 whitespace-nowrap" :class="tab === {{ $category->id }} && 'chip-active'"
                                @click="tab = {{ $category->id }}" :aria-selected="tab === {{ $category->id }}">{{ $category->name }}</button>
                    @endforeach
                </div>
                @foreach ($categories as $category)
                    <div x-show="tab === {{ $category->id }}" @if (! $loop->first) x-cloak @endif role="tabpanel" class="card mt-6 p-6 sm:p-10">
                        <ul class="grid gap-x-12 gap-y-1 md:grid-cols-2">
                            @foreach ($category->services->take((int) $section->setting('limit', 12)) as $service)
                                <li class="group py-4">
                                    <div class="flex items-baseline gap-3">
                                        <h3 class="font-sans text-base font-medium tracking-normal">{{ $service->name }}</h3>
                                        <span class="price-dots" aria-hidden="true"></span>
                                        <p class="shrink-0 font-display text-lg"><span class="me-1 text-xs text-muted">{{ __('site.home.from') }}</span>{{ money($service->sale_price ?? $service->price) }}</p>
                                    </div>
                                    <div class="mt-1 flex items-center justify-between gap-3 text-xs text-muted">
                                        <span>{{ $service->duration_minutes }} {{ __('salon.ui.minutes') }}</span>
                                        @if ($tenant->hasModule('booking'))
                                            <a href="{{ url('/book').'?service='.$service->slug }}" class="font-medium text-gold-dark underline-offset-4 transition group-hover:underline">{{ __('website.labels.book_this') }} →</a>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>
