@php($team = $data->team((int) $section->setting('limit', 8)))
@if ($team->isNotEmpty())
<section class="site-section">
    <div class="container-luxe">
        <div class="max-w-2xl" data-reveal>
            <p class="eyebrow">{{ __('website.labels.team') }}</p>
            <h2 class="mt-3 text-3xl font-semibold sm:text-5xl">{{ $section->t('title', __('site.home.team_title')) }}</h2>
            @if ($section->t('subtitle'))<p class="mt-4 text-muted">{{ $section->t('subtitle') }}</p>@endif
        </div>
        <div class="mt-12 grid grid-cols-2 gap-x-6 gap-y-10 md:grid-cols-4">
            @foreach ($team as $member)
                <article class="group" data-reveal>
                    <div class="aspect-[4/5] overflow-hidden rounded-(--radius-card) bg-gradient-to-b from-rose/70 to-ivory">
                        @if ($member->photo_path)
                            <x-site.picture :path="$member->photo_path" :alt="$member->name" sizes="(min-width: 768px) 22vw, 45vw" class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]" />
                        @else
                            <div class="flex h-full items-center justify-center font-display text-5xl text-ink/30" aria-hidden="true">
                                {{ collect(explode(' ', $member->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}
                            </div>
                        @endif
                    </div>
                    <h3 class="mt-4 text-lg font-semibold">{{ $member->name }}</h3>
                    <p class="text-sm text-muted">{{ $member->title }}</p>
                    @if ($member->ratings_count > 0)
                        <p class="mt-1 text-xs text-gold-dark" aria-label="{{ __('website.labels.rating', ['rating' => number_format($member->rating, 1)]) }}">★ {{ number_format($member->rating, 1) }} <span class="text-muted">({{ $member->ratings_count }})</span></p>
                    @endif
                </article>
            @endforeach
        </div>
    </div>
</section>
@endif
