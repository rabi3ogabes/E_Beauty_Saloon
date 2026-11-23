<x-layouts.site :title="__('booking.confirmation.title')">
    <section class="container-luxe max-w-3xl py-12 sm:py-16">
        <div class="text-center" data-reveal>
            @if ($pending && $payment?->status === 'pending')
                <p class="eyebrow">{{ __('booking.confirmation.processing_eyebrow') }}</p>
                <h1 class="mt-3 text-3xl font-semibold sm:text-4xl">{{ __('booking.confirmation.processing') }}</h1>
                <p class="mt-3 text-muted">{{ __('booking.confirmation.processing_sub') }}</p>
                <meta http-equiv="refresh" content="6">
            @elseif ($booking->status->isCancelled())
                <p class="eyebrow text-danger">{{ __('booking.confirmation.not_completed_eyebrow') }}</p>
                <h1 class="mt-3 text-3xl font-semibold sm:text-4xl">{{ __('booking.confirmation.not_completed') }}</h1>
                <p class="mt-3 text-muted">{{ __('booking.confirmation.not_completed_sub') }}</p>
                <a href="{{ $links['rebook'] }}" class="btn-primary mt-6">{{ __('booking.confirmation.try_again') }}</a>
            @else
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-gold/15 text-2xl text-gold-dark" aria-hidden="true">✦</span>
                <p class="eyebrow mt-5">{{ __('booking.confirmation.eyebrow') }}</p>
                <h1 class="mt-3 text-3xl font-semibold sm:text-4xl">{{ __('booking.confirmation.heading', ['name' => $booking->client?->firstName()]) }}</h1>
                <p class="mt-3 text-muted">{{ __('booking.confirmation.whatsapp_sent') }}</p>
            @endif
        </div>

        <div class="mt-10">@include('site.booking.partials.summary')</div>

        @unless ($booking->status->isCancelled())
            <div class="mt-6 grid gap-3 sm:grid-cols-3">
                <a href="{{ $links['ics'] }}" class="btn-ghost">{{ __('booking.confirmation.add_calendar') }}</a>
                <a href="{{ $links['google'] }}" target="_blank" rel="noopener" class="btn-ghost">{{ __('booking.confirmation.google_calendar') }}</a>
                <a href="{{ $links['manage'] }}" class="btn-primary">{{ __('booking.confirmation.manage') }}</a>
            </div>
        @endunless
        <p class="mt-8 text-center text-xs text-muted">{{ __('booking.confirmation.policy', ['hours' => \App\Domain\Booking\Support\BookingSettings::get('cancellation_window_hours')]) }}</p>
    </section>
</x-layouts.site>
