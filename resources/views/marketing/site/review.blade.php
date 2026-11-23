<x-layouts.site :title="__('marketing.reviews.public_title')">
    @php
        $locale = app()->getLocale();
        $staff = $booking->items->first(fn ($i) => $i->staff)?->staff;
        $services = $booking->items->map(fn ($i) => $i->service?->getTranslation('name', $locale))->filter()->unique()->implode(' · ');
    @endphp
    <section class="container-luxe py-16 sm:py-24">
        <div class="mx-auto max-w-lg">
            @if ($review)
                <div class="text-center">
                    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gold/15 text-2xl text-gold-dark">✦</div>
                    <h1 class="mt-6 text-3xl font-semibold sm:text-4xl">{{ __('marketing.reviews.thanks_title') }}</h1>
                    <p class="mt-4 text-muted">{{ $review->status === 'published' ? __('marketing.reviews.thanks_published') : __('marketing.reviews.thanks_private') }}</p>
                    @if ($currentTenant->hasModule('booking'))<a href="{{ url('/book') }}" class="btn-primary mt-8">{{ __('marketing.reviews.book_again') }}</a>@endif
                </div>
            @else
                <div class="text-center">
                    <p class="eyebrow">{{ $currentTenant->name }}</p>
                    <h1 class="mt-3 text-3xl font-semibold sm:text-4xl">{{ __('marketing.reviews.question', ['name' => $booking->client?->firstName() ?? '']) }}</h1>
                    <p class="mt-3 text-sm text-muted">{{ $services }}@if ($staff) · {{ __('marketing.reviews.with', ['staff' => $staff->getTranslation('name', $locale)]) }}@endif</p>
                </div>
                <form method="POST" action="{{ \Illuminate\Support\Facades\URL::signedRoute('marketing.review.store', ['reference' => $booking->reference], absolute: false) }}" class="card mt-10 space-y-6 p-6 sm:p-8" x-data="{ rating: {{ (int) old('rating', 0) }}, hover: 0 }">
                    @csrf
                    <fieldset>
                        <legend class="sr-only">{{ __('marketing.reviews.rating') }}</legend>
                        <div class="flex justify-center gap-2" dir="ltr" @mouseleave="hover = 0">
                            @for ($i = 1; $i <= 5; $i++)
                                <label class="cursor-pointer p-1" @mouseenter="hover = {{ $i }}">
                                    <input type="radio" name="rating" value="{{ $i }}" class="sr-only" x-model.number="rating" required>
                                    <svg class="h-11 w-11 transition duration-150" :class="(hover || rating) >= {{ $i }} ? 'text-gold scale-105' : 'text-line'" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5l2.9 6.1 6.6.8-4.9 4.6 1.3 6.6L12 17.3l-5.9 3.3 1.3-6.6L2.5 9.4l6.6-.8z"/></svg>
                                    <span class="sr-only">{{ trans_choice('marketing.reviews.stars', $i, ['count' => $i]) }}</span>
                                </label>
                            @endfor
                        </div>
                        <p class="mt-2 h-5 text-center text-sm text-muted" x-text="['', @js(__('marketing.reviews.labels.1')), @js(__('marketing.reviews.labels.2')), @js(__('marketing.reviews.labels.3')), @js(__('marketing.reviews.labels.4')), @js(__('marketing.reviews.labels.5'))][hover || rating]"></p>
                    </fieldset>
                    <div>
                        <label class="label" for="comment">{{ __('marketing.reviews.comment') }}</label>
                        <textarea id="comment" name="comment" rows="4" maxlength="2000" class="input" placeholder="{{ __('marketing.reviews.comment_placeholder') }}">{{ old('comment') }}</textarea>
                    </div>
                    @if ($errors->any())<p class="text-sm text-danger">{{ $errors->first() }}</p>@endif
                    <button class="btn-primary w-full" :disabled="!rating">{{ __('marketing.reviews.submit') }}</button>
                </form>
            @endif
        </div>
    </section>
</x-layouts.site>
