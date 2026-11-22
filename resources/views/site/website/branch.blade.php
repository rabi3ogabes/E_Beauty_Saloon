@php
    use App\Domain\Branches\Models\Branch;
    use App\Domain\Websites\Support\SiteSeo;
    $locale = app()->getLocale();
    $area = $branch->area ?: $branch->city;
    $seoTitle = __('website.branch.seo_title', ['area' => $area ?: $branch->name, 'salon' => $tenant->name]);
    $days = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];
    $hours = $branch->opening_hours ?? Branch::defaultHours();
    $marker = [['lat' => $branch->lat, 'lng' => $branch->lng, 'name' => $branch->name, 'area' => $branch->area, 'url' => null]];
@endphp
<x-layouts.site :title="$seoTitle" :description="__('website.branch.seo_description', ['salon' => $tenant->name, 'area' => $area ?: $branch->name])" :og-image="$branch->cover_path ? asset('storage/'.$branch->cover_path) : null">
    @push('head')
        {!! SiteSeo::jsonLd($jsonLd) !!}
        {!! SiteSeo::jsonLd(SiteSeo::breadcrumbs([$tenant->name => url('/'), $branch->getTranslation('name', $locale) => url('/branches/'.$branch->slug)])) !!}
    @endpush

    <section class="site-glow">
        <div class="container-luxe grid items-center gap-10 py-12 md:grid-cols-[1.2fr_1fr] md:py-20">
            <div>
                <nav class="text-xs text-muted" aria-label="Breadcrumb"><a href="{{ url('/') }}" class="hover:text-ink">{{ $tenant->name }}</a> / <span>{{ $branch->name }}</span></nav>
                <p class="eyebrow mt-6">{{ __('website.branch.eyebrow', ['area' => $area ?: $branch->name]) }}</p>
                <h1 class="hero-title mt-4 font-semibold text-balance">{{ __('website.branch.h1', ['area' => $area ?: $branch->name]) }}</h1>
                <p class="mt-6 max-w-lg text-lg text-muted">{{ __('website.branch.intro', ['salon' => $tenant->name, 'area' => $area ?: $branch->name]) }}</p>
                <div class="mt-9 flex flex-wrap gap-3">
                    @if ($tenant->hasModule('booking'))<a href="{{ url('/book?branch='.$branch->slug) }}" class="btn-primary px-8">{{ __('website.branch.book_here') }}</a>@endif
                    @if ($gbp)<a href="{{ $gbp }}" target="_blank" rel="noopener" class="btn-ghost">{{ __('website.branch.google') }}</a>@endif
                    @if ($branch->whatsapp)<a href="https://wa.me/{{ preg_replace('/\D+/', '', $branch->whatsapp) }}" target="_blank" rel="noopener" class="btn-ghost">WhatsApp</a>@endif
                </div>
            </div>
            <div class="hero-media mx-auto w-full max-w-md">
                @if ($branch->cover_path || $tenant->brand('hero_path'))
                    <x-site.picture :path="$branch->cover_path ?: $tenant->brand('hero_path')" :alt="$branch->name" :eager="true" sizes="(min-width: 768px) 40vw, 90vw" width="800" height="1000" />
                @endif
            </div>
        </div>
    </section>

    <section class="site-section pt-0!">
        <div class="container-luxe grid gap-6 lg:grid-cols-3">
            <div class="card p-6 sm:p-8">
                <h2 class="text-xl font-semibold">{{ __('website.labels.address') }}</h2>
                <address class="mt-3 not-italic text-muted">{{ $branch->getTranslation('address', $locale, false) ?: trim($branch->area.', '.$branch->city, ', ') }}</address>
                @if ($branch->phone)<p class="mt-4" dir="ltr"><a href="tel:{{ $branch->phone }}" class="hover:text-gold-dark">{{ $branch->phone }}</a></p>@endif
                @if ($branch->lat && $branch->lng)
                    <a href="https://www.google.com/maps/dir/?api=1&destination={{ $branch->lat }},{{ $branch->lng }}" target="_blank" rel="noopener" class="mt-4 inline-block text-sm font-medium text-gold-dark">{{ __('website.labels.directions') }} →</a>
                @endif
            </div>
            <div class="card p-6 sm:p-8">
                <h2 class="text-xl font-semibold">{{ __('website.labels.hours') }}</h2>
                <dl class="mt-3 space-y-1.5 text-sm">
                    @foreach ($days as $d)
                        @php($h = $hours[$d] ?? null)
                        <div @class(['flex justify-between', 'font-semibold' => strtolower(now($branch->timezone)->format('D')) === $d])>
                            <dt>{{ __('website.days.'.$d) }}</dt>
                            <dd dir="ltr">{{ ! $h || ($h['closed'] ?? false) ? __('site.home.closed_now') : $h['open'].' – '.$h['close'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
            @if ($branch->lat && $branch->lng)
                <div class="site-map min-h-64" data-map='@json($marker)' role="img" aria-label="{{ __('website.labels.map') }}"></div>
                @push('scripts')
                    @vite('resources/js/map.js')
                @endpush
            @endif
        </div>
    </section>

    @if ($categories->isNotEmpty())
        <section class="site-section">
            <div class="container-luxe">
                <h2 class="text-3xl font-semibold sm:text-4xl">{{ __('website.branch.services', ['area' => $area ?: $branch->name]) }}</h2>
                <div class="mt-8 grid gap-6 md:grid-cols-2">
                    @foreach ($categories as $category)
                        <div class="card p-6 sm:p-8" data-reveal>
                            <h3 class="text-xl font-semibold">{{ $category->name }}</h3>
                            <ul class="mt-4 divide-y hairline">
                                @foreach ($category->services as $service)
                                    <li class="flex items-baseline gap-3 py-3">
                                        <span class="font-medium">{{ $service->name }}</span>
                                        <span class="price-dots" aria-hidden="true"></span>
                                        <span class="shrink-0 text-sm">{{ money($service->priceAt($branch)) }} · <span class="text-muted">{{ $service->durationAt($branch) }} {{ __('salon.ui.minutes') }}</span></span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($team->isNotEmpty())
        <section class="site-section">
            <div class="container-luxe">
                <h2 class="text-3xl font-semibold sm:text-4xl">{{ __('website.branch.team') }}</h2>
                <div class="mt-8 flex flex-wrap gap-3">
                    @foreach ($team as $member)<span class="chip min-h-11">{{ $member->name }} <span class="text-xs text-muted">· {{ $member->title }}</span></span>@endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($others->isNotEmpty())
        <section class="container-luxe pb-10">
            <p class="text-sm text-muted">{{ __('website.branch.others') }}
                @foreach ($others as $other)<a href="{{ url('/branches/'.$other->slug) }}" class="ms-2 font-medium text-gold-dark">{{ $other->name }}</a>@endforeach
            </p>
        </section>
    @endif
</x-layouts.site>
