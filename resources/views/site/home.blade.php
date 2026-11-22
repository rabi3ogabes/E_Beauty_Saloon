<x-layouts.site :title="null">
    {{-- Hero --}}
    <section class="relative overflow-hidden">
        <div class="container-luxe grid items-center gap-12 py-16 md:grid-cols-2 md:py-24">
            <div data-reveal>
                <p class="eyebrow">{{ __('site.home.eyebrow') }}</p>
                <h1 class="mt-4 text-4xl leading-[1.08] font-semibold sm:text-5xl lg:text-6xl">{{ $tenant->name }}</h1>
                @if ($tenant->brand('about'))
                    <p class="mt-6 max-w-lg text-lg text-muted">{{ \Illuminate\Support\Str::limit(strip_tags($tenant->brand('about')), 200) }}</p>
                @endif
                <div class="mt-9 flex flex-wrap gap-3">
                    @if ($tenant->hasModule('booking'))<a href="{{ url('/book') }}" class="btn-primary">{{ __('site.cta.book_now') }}</a>@endif
                    <a href="#services" class="btn-ghost">{{ __('site.cta.explore') }}</a>
                </div>
            </div>
            <div class="relative" data-reveal>
                <div class="aspect-[4/5] overflow-hidden rounded-(--radius-hero) bg-gradient-to-br from-rose via-ivory to-gold/40 shadow-lift">
                    @if ($tenant->brand('hero_path'))
                        <img src="{{ asset('storage/'.$tenant->brand('hero_path')) }}" alt="" class="h-full w-full object-cover" fetchpriority="high">
                    @endif
                </div>
                @if ($tenant->hasModule('ai_studio'))
                    <a href="{{ url('/studio') }}" class="glass absolute -bottom-6 start-6 flex items-center gap-3 rounded-2xl px-5 py-4 shadow-soft">
                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gold/20 text-gold-dark">✦</span>
                        <span class="text-sm font-medium">{{ __('site.cta.try_studio') }}</span>
                    </a>
                @endif
            </div>
        </div>
    </section>

    {{-- Services menu --}}
    <section id="services" class="container-luxe py-16">
        <div class="max-w-2xl" data-reveal>
            <h2 class="text-3xl font-semibold sm:text-4xl">{{ __('site.home.services_title') }}</h2>
            <p class="mt-3 text-muted">{{ __('site.home.services_sub') }}</p>
        </div>
        <div class="mt-10 grid gap-6 md:grid-cols-2">
            @foreach ($categories->filter(fn ($c) => $c->services->isNotEmpty()) as $category)
                <div class="card p-6 sm:p-8" data-reveal>
                    <h3 class="text-xl font-semibold">{{ $category->name }}</h3>
                    <ul class="mt-5 divide-y hairline">
                        @foreach ($category->services->take(6) as $service)
                            <li class="flex items-baseline justify-between gap-4 py-3">
                                <div>
                                    <p class="font-medium">{{ $service->name }}</p>
                                    <p class="text-xs text-muted">{{ $service->duration_minutes }} {{ __('salon.ui.minutes') }}</p>
                                </div>
                                <p class="shrink-0 text-sm"><span class="text-muted">{{ __('site.home.from') }}</span> {{ money($service->sale_price ?? $service->price) }}</p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Team --}}
    @if ($team->isNotEmpty())
        <section class="container-luxe py-16">
            <h2 class="text-3xl font-semibold sm:text-4xl" data-reveal>{{ __('site.home.team_title') }}</h2>
            <div class="mt-10 grid grid-cols-2 gap-6 md:grid-cols-4">
                @foreach ($team as $member)
                    <div class="text-center" data-reveal>
                        <div class="mx-auto aspect-square w-full overflow-hidden rounded-(--radius-card) bg-rose/60">
                            @if ($member->photo_path)<img src="{{ asset('storage/'.$member->photo_path) }}" alt="{{ $member->name }}" loading="lazy" class="h-full w-full object-cover">@endif
                        </div>
                        <p class="mt-4 font-medium">{{ $member->name }}</p>
                        <p class="text-sm text-muted">{{ $member->title }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Branches --}}
    <section class="container-luxe py-16">
        <h2 class="text-3xl font-semibold sm:text-4xl" data-reveal>{{ __('site.home.branches_title') }}</h2>
        <div class="mt-10 grid gap-6 md:grid-cols-3">
            @foreach ($branches as $branch)
                <a href="{{ url('/branches/'.$branch->slug) }}" class="card-hover block p-6" data-reveal>
                    <div class="flex items-center justify-between">
                        <p class="text-lg font-semibold">{{ $branch->name }}</p>
                        <span @class(['rounded-full px-2.5 py-1 text-xs', 'bg-success/10 text-success' => $branch->isOpenNow(), 'bg-line text-muted' => ! $branch->isOpenNow()])>
                            {{ $branch->isOpenNow() ? __('site.home.open_now') : __('site.home.closed_now') }}
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-muted">{{ $branch->area }}{{ $branch->city ? ', '.$branch->city : '' }}</p>
                    @if ($branch->phone)<p class="mt-4 text-sm" dir="ltr">{{ $branch->phone }}</p>@endif
                </a>
            @endforeach
        </div>
    </section>
</x-layouts.site>
