@php
    $branches = $data->branches();
    $markers = $branches->filter(fn ($b) => $b->lat && $b->lng)->map(fn ($b) => [
        'lat' => $b->lat, 'lng' => $b->lng, 'name' => $b->name, 'area' => $b->area, 'url' => url('/branches/'.$b->slug),
    ])->values();
@endphp
@if ($branches->isNotEmpty())
<section id="visit" class="site-section">
    <div class="container-luxe">
        <div class="max-w-2xl" data-reveal>
            <p class="eyebrow">{{ __('site.footer.visit') }}</p>
            <h2 class="mt-3 text-3xl font-semibold sm:text-5xl">{{ $section->t('title', __('site.home.branches_title')) }}</h2>
            @if ($section->t('subtitle'))<p class="mt-4 text-muted">{{ $section->t('subtitle') }}</p>@endif
        </div>
        <div class="mt-10 grid gap-6 lg:grid-cols-[1fr_1.4fr]">
            <div class="flex flex-col gap-4">
                @foreach ($branches as $branch)
                    <a href="{{ url('/branches/'.$branch->slug) }}" class="card-hover block p-6" data-reveal>
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-lg font-semibold">{{ $branch->name }}</h3>
                            <span @class(['rounded-full px-2.5 py-1 text-xs', 'bg-success/10 text-success' => $branch->isOpenNow(), 'bg-line text-muted' => ! $branch->isOpenNow()])>
                                {{ $branch->isOpenNow() ? __('site.home.open_now') : __('site.home.closed_now') }}
                            </span>
                        </div>
                        <p class="mt-2 text-sm text-muted">{{ $branch->getTranslation('address', app()->getLocale(), false) ?: trim($branch->area.', '.$branch->city, ', ') }}</p>
                        @if ($branch->phone)<p class="mt-3 text-sm" dir="ltr">{{ $branch->phone }}</p>@endif
                    </a>
                @endforeach
            </div>
            @if ($markers->isNotEmpty())
                <div class="site-map" data-map='@json($markers)' role="img" aria-label="{{ __('website.labels.map') }}"></div>
                @once
                    @push('scripts')
                        @vite('resources/js/map.js')
                    @endpush
                @endonce
            @endif
        </div>
    </div>
</section>
@endif
