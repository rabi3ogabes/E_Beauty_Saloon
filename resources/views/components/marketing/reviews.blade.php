{{--
    Published client reviews for the salon website.  Usage:  <x-marketing.reviews :limit="6" />
    Props: limit (int, default 6), minRating (int, default 4), staffId (?int), branchId (?int), title (?string, null = default, false = none)
--}}
@props(['limit' => 6, 'minRating' => 4, 'staffId' => null, 'branchId' => null, 'title' => null])
@php
    $reviewsQuery = \App\Domain\Marketing\Models\Review::query()->published()->where('rating', '>=', $minRating)
        ->when($staffId, fn ($q) => $q->where('staff_id', $staffId))
        ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
    $reviews = tenant() ? (clone $reviewsQuery)->with(['client:id,name', 'staff'])->latest()->limit($limit)->get() : collect();
    $stats = tenant() ? \App\Domain\Marketing\Models\Review::query()->whereIn('status', ['published', 'private'])->selectRaw('avg(rating) as avg, count(*) as c')->first() : null;
    $locale = app()->getLocale();
@endphp
@if ($reviews->isNotEmpty())
    <section {{ $attributes->merge(['class' => 'container-luxe py-16']) }} aria-labelledby="reviews-title">
        @if ($title !== false)
            <div class="flex flex-wrap items-end justify-between gap-4" data-reveal>
                <div>
                    <p class="eyebrow">{{ __('marketing.reviews.widget_eyebrow') }}</p>
                    <h2 id="reviews-title" class="mt-2 text-3xl font-semibold sm:text-4xl">{{ $title ?? __('marketing.reviews.widget_title') }}</h2>
                </div>
                @if ($stats && $stats->c)
                    <p class="flex items-center gap-2 text-sm text-muted"><span class="font-display text-2xl text-ink dark:text-ivory">{{ number_format((float) $stats->avg, 1) }}</span><span class="text-gold">★</span>{{ trans_choice('marketing.reviews.count', (int) $stats->c, ['count' => (int) $stats->c]) }}</p>
                @endif
            </div>
        @endif
        <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($reviews as $review)
                <figure class="card flex flex-col p-6" data-reveal>
                    <div class="flex gap-0.5 text-gold" aria-label="{{ trans_choice('marketing.reviews.stars', $review->rating, ['count' => $review->rating]) }}" dir="ltr">
                        @for ($i = 1; $i <= 5; $i++)<span @class(['opacity-25' => $i > $review->rating])>★</span>@endfor
                    </div>
                    @if ($review->comment)<blockquote class="mt-4 flex-1 leading-relaxed">“{{ \Illuminate\Support\Str::limit($review->comment, 280) }}”</blockquote>@endif
                    <figcaption class="mt-5 text-sm text-muted">
                        {{ $review->client ? \Illuminate\Support\Str::before(trim($review->client->name), ' ') : __('marketing.reviews.anonymous') }}
                        @if ($review->staff) · {{ __('marketing.reviews.with', ['staff' => $review->staff->getTranslation('name', $locale)]) }}@endif
                    </figcaption>
                    @if ($review->reply)
                        <p class="mt-4 rounded-xl bg-ivory p-3 text-xs text-muted dark:bg-white/5"><span class="font-medium text-ink dark:text-ivory">{{ tenant()->name }}:</span> {{ $review->reply }}</p>
                    @endif
                </figure>
            @endforeach
        </div>
    </section>
@endif
