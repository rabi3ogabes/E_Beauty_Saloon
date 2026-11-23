<x-layouts.site :title="__('booking.manage.title')">
    <section class="container-luxe max-w-3xl py-12 sm:py-16" x-data="manageBooking(@js([
        'slotsUrl' => route('booking.slots', $booking->reference),
        'rescheduleUrl' => route('booking.reschedule', $booking->reference),
        'locale' => app()->getLocale(),
        'labels' => ['periods' => __('booking.periods'), 'error' => __('booking.flow.generic_error')],
    ]))">
        <p class="eyebrow">{{ __('booking.manage.eyebrow') }}</p>
        <h1 class="mt-2 text-3xl font-semibold sm:text-4xl">{{ __('booking.manage.title') }}</h1>

        @if (session('status'))<p class="mt-6 rounded-xl bg-success/10 px-4 py-3 text-sm text-success" role="status">{{ session('status') }}</p>@endif
        @if ($errors->any())<p class="mt-6 rounded-xl bg-danger/10 px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first() }}</p>@endif

        <div class="mt-8">@include('site.booking.partials.summary')</div>

        <div class="mt-6 flex flex-wrap gap-3">
            @if (! $booking->status->isCancelled())
                <a href="{{ $links['ics'] }}" class="btn-ghost">{{ __('booking.confirmation.add_calendar') }}</a>
                <a href="{{ $links['google'] }}" target="_blank" rel="noopener" class="btn-ghost">{{ __('booking.confirmation.google_calendar') }}</a>
            @endif
            <a href="{{ $links['rebook'] }}" class="btn-ghost">{{ __('booking.account.rebook') }}</a>
            @if ($policy['can_pay'])
                <form method="POST" action="{{ route('booking.pay', $booking->reference) }}">@csrf<button class="btn-gold">{{ __('booking.manage.pay_now', ['amount' => money($booking->balanceDue(), $booking->currency)]) }}</button></form>
            @endif
        </div>

        @if ($policy['can_reschedule'])
            <div class="card mt-8 p-6 sm:p-8">
                <h2 class="text-xl font-semibold">{{ __('booking.manage.reschedule') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ trans_choice('booking.manage.reschedules_left', $policy['reschedules_left'], ['count' => $policy['reschedules_left']]) }}</p>
                <div class="-mx-2 mt-5 flex gap-2 overflow-x-auto px-2 pb-2 [scrollbar-width:none]">
                    @foreach ($dates as $d)
                        <button type="button" class="flex w-16 shrink-0 flex-col items-center rounded-2xl border py-3 transition" @disabled($d['closed'])
                                :class="date === '{{ $d['date'] }}' ? 'border-ink bg-ink text-ivory' : '{{ $d['closed'] ? 'border-line text-muted/60' : 'border-line bg-white hover:border-gold' }}'"
                                @click="load('{{ $d['date'] }}')" aria-label="{{ $d['label'] }}">
                            <span class="text-[11px] uppercase tracking-wider opacity-80">{{ $d['dow'] }}</span>
                            <span class="font-display text-xl leading-tight">{{ $d['day'] }}</span>
                            <span class="text-[11px] opacity-70">{{ $d['month'] }}</span>
                        </button>
                    @endforeach
                </div>
                <div class="mt-4" aria-live="polite">
                    <template x-if="loading"><div class="grid grid-cols-3 gap-2 sm:grid-cols-5"><template x-for="i in 10"><div class="skeleton h-11"></div></template></div></template>
                    <div x-show="!loading && day">
                        <template x-for="period in ['morning', 'afternoon', 'evening']" :key="period">
                            <section x-show="day?.periods?.[period]?.length" class="mb-4">
                                <h3 class="text-sm text-muted" x-text="labels.periods[period]"></h3>
                                <div class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-5">
                                    <template x-for="slot in (day?.periods?.[period] || [])" :key="slot.starts_at">
                                        <button type="button" class="chip min-h-11 justify-center" dir="ltr" :class="picked === slot.starts_at && 'chip-active'" @click="picked = slot.starts_at" x-text="slot.time"></button>
                                    </template>
                                </div>
                            </section>
                        </template>
                        <p x-show="day && day.count === 0" class="text-sm text-muted">{{ __('booking.flow.no_slots') }} <button type="button" class="text-gold-dark underline" x-show="day?.next_available_date" @click="load(day.next_available_date)">{{ __('booking.flow.jump_next') }} <span x-text="day?.next_available_label"></span></button></p>
                    </div>
                    <p x-show="error" class="mt-3 text-sm text-danger" x-text="error"></p>
                    <button type="button" class="btn-primary mt-4" :disabled="!picked || busy" @click="submit()">{{ __('booking.manage.confirm_reschedule') }}</button>
                </div>
            </div>
        @elseif (in_array($booking->status->value, ['pending_payment', 'confirmed'], true) && $booking->starts_at->isFuture())
            <p class="mt-8 text-sm text-muted">{{ $policy['reschedules_left'] === 0 ? __('booking.manage.no_reschedules_left') : __('booking.manage.reschedule_closed', ['hours' => $policy['window_hours']]) }}</p>
        @endif

        @if ($policy['can_cancel'])
            <form method="POST" action="{{ route('booking.cancel', $booking->reference) }}" class="card mt-6 p-6 sm:p-8" x-data="{ open: false }">
                @csrf
                <h2 class="text-xl font-semibold">{{ __('booking.manage.cancel') }}</h2>
                <p class="mt-1 text-sm text-muted">
                    @if ($policy['late'] && $policy['late_fee'] > 0)
                        {{ __('booking.manage.late_fee_warning', ['fee' => money($policy['late_fee'], $booking->currency), 'hours' => $policy['window_hours']]) }}
                    @elseif ($booking->amount_paid > 0)
                        {{ __('booking.manage.full_refund', ['amount' => money($booking->amount_paid, $booking->currency)]) }}
                    @else
                        {{ __('booking.manage.cancel_free') }}
                    @endif
                </p>
                <button type="button" class="btn-ghost mt-4" x-show="!open" @click="open = true">{{ __('booking.manage.cancel') }}</button>
                <div x-show="open" x-cloak x-transition class="mt-4 space-y-3">
                    <label class="label" for="reason">{{ __('booking.manage.reason') }} <span class="text-muted">({{ __('salon.ui.optional') }})</span></label>
                    <input id="reason" name="reason" class="input" maxlength="250">
                    <button class="btn bg-danger text-white">{{ __('booking.manage.confirm_cancel') }}</button>
                </div>
            </form>
        @endif
    </section>

    @push('scripts')
        <script>
            function manageBooking(cfg) {
                return {
                    ...cfg, date: null, day: null, loading: false, picked: null, busy: false, error: null,
                    async load(d) {
                        this.date = d; this.loading = true; this.picked = null; this.error = null;
                        try {
                            const r = await fetch(this.slotsUrl + '?date=' + d, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                            this.day = await r.json();
                        } catch (e) { this.error = this.labels.error; } finally { this.loading = false; }
                    },
                    async submit() {
                        this.busy = true; this.error = null;
                        const r = await fetch(this.rescheduleUrl, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ starts_at: this.picked }) });
                        const data = await r.json().catch(() => ({}));
                        if (r.ok) { window.location.reload(); } else { this.error = data.message || this.labels.error; this.busy = false; }
                    },
                };
            }
        </script>
    @endpush
</x-layouts.site>
