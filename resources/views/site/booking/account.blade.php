@php($link = fn ($b) => \App\Domain\Booking\Support\BookingLinks::manage($b))
<div class="space-y-10">
    <section>
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-xl font-semibold">{{ __('booking.account.upcoming') }}</h2>
            <a href="{{ url('/book') }}" class="btn-primary min-h-10 px-4 text-xs">{{ __('booking.account.book_now') }}</a>
        </div>
        <ul class="mt-4 space-y-3">
            @forelse ($upcoming as $b)
                @php($local = $b->starts_at->copy()->setTimezone($b->branch?->timezone ?? 'UTC'))
                <li class="card flex flex-wrap items-center justify-between gap-4 p-5">
                    <div class="flex items-center gap-4">
                        <div class="flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded-2xl bg-gold/15 text-gold-dark">
                            <span class="text-[11px] uppercase">{{ $local->translatedFormat('M') }}</span>
                            <span class="font-display text-xl leading-none">{{ $local->format('j') }}</span>
                        </div>
                        <div class="min-w-0">
                            <p class="font-medium">{{ $b->items->map(fn ($i) => $i->service?->name)->filter()->implode(' + ') }}</p>
                            <p class="text-sm text-muted">{{ $local->translatedFormat('l') }} · <span dir="ltr">{{ $local->format('H:i') }}</span> · {{ $b->branch?->name }}</p>
                            <p class="text-xs text-muted">{{ $b->status->getLabel() }} · <span dir="ltr">{{ $b->reference }}</span></p>
                        </div>
                    </div>
                    <a href="{{ $link($b) }}" class="btn-ghost min-h-10 px-4 text-xs">{{ __('booking.account.manage') }}</a>
                </li>
            @empty
                <li class="card p-8 text-center">
                    <p class="font-display text-lg">{{ __('booking.account.empty_upcoming') }}</p>
                    <a href="{{ url('/book') }}" class="btn-primary mt-4">{{ __('booking.account.book_now') }}</a>
                </li>
            @endforelse
        </ul>
    </section>

    <section>
        <h2 class="text-xl font-semibold">{{ __('booking.account.past') }}</h2>
        <ul class="mt-4 divide-y hairline card">
            @forelse ($past as $b)
                @php($local = $b->starts_at->copy()->setTimezone($b->branch?->timezone ?? 'UTC'))
                <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <p class="font-medium">{{ $b->items->map(fn ($i) => $i->service?->name)->filter()->implode(' + ') }}</p>
                        <p class="text-xs text-muted">{{ $local->translatedFormat('j F Y') }} · {{ $b->items->map(fn ($i) => $i->staff?->name)->filter()->unique()->implode(', ') }} · {{ $b->status->getLabel() }}</p>
                    </div>
                    <a href="{{ \App\Domain\Booking\Support\BookingLinks::rebook($b) }}" class="chip">{{ __('booking.account.rebook') }}</a>
                </li>
            @empty
                <li class="p-6 text-center text-sm text-muted">{{ __('booking.account.empty_past') }}</li>
            @endforelse
        </ul>
    </section>
</div>
