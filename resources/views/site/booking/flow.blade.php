<x-layouts.site :title="__('booking.flow.title')" :description="__('booking.flow.meta')">
    <section class="container-luxe pb-40 pt-8 sm:pt-12 lg:pb-16" x-data="bookingFlow(@js($state))" x-init="init()" x-cloak>
        {{-- Heading + progress --}}
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow">{{ __('booking.flow.eyebrow') }}</p>
                <h1 class="mt-2 text-3xl font-semibold sm:text-4xl" x-text="stepTitle()"></h1>
            </div>
            <button type="button" class="btn-ghost min-h-10 px-4 text-xs" x-show="stepIndex() > 0" @click="back()">
                <svg class="h-4 w-4 rtl:rotate-180" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                {{ __('salon.ui.back') }}
            </button>
        </div>
        <ol class="mt-6 flex gap-1.5" aria-label="{{ __('booking.flow.progress') }}">
            <template x-for="(s, i) in steps()" :key="s">
                <li class="h-1 flex-1 rounded-full transition-colors duration-300" :class="i <= stepIndex() ? 'bg-gold' : 'bg-line'" :aria-current="i === stepIndex() ? 'step' : null">
                    <span class="sr-only" x-text="labels.steps[s]"></span>
                </li>
            </template>
        </ol>

        <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_340px]">
            <div>
                {{-- Toast --}}
                <div aria-live="assertive" class="mb-4">
                    <p x-show="error" x-transition.opacity class="rounded-xl bg-danger/10 px-4 py-3 text-sm text-danger" x-text="error"></p>
                </div>

                {{-- 1. Branch --}}
                <div x-show="step === 'branch'" x-transition.opacity.duration.200ms>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <template x-for="b in branches" :key="b.id">
                            <button type="button" class="card-hover overflow-hidden text-start" @click="chooseBranch(b.id)" :aria-pressed="branchId === b.id">
                                <div class="aspect-[16/9] bg-gradient-to-br from-rose via-ivory to-gold/30">
                                    <img x-show="b.image" :src="b.image" alt="" class="h-full w-full object-cover" loading="lazy">
                                </div>
                                <div class="p-5">
                                    <p class="font-display text-xl" x-text="b.name"></p>
                                    <p class="mt-1 text-sm text-muted" x-text="b.address || b.area"></p>
                                </div>
                            </button>
                        </template>
                    </div>
                </div>

                {{-- 2. Services --}}
                <div x-show="step === 'services'" x-transition.opacity.duration.200ms>
                    <template x-if="loadingCatalog">
                        <div class="space-y-3" aria-busy="true">
                            <div class="skeleton h-11 w-full"></div>
                            <div class="flex gap-2"><div class="skeleton h-9 w-20"></div><div class="skeleton h-9 w-24"></div><div class="skeleton h-9 w-16"></div></div>
                            <template x-for="i in 5"><div class="skeleton h-20 w-full"></div></template>
                        </div>
                    </template>
                    <div x-show="!loadingCatalog && catalog">
                        <label class="sr-only" for="svc-search">{{ __('salon.ui.search') }}</label>
                        <input id="svc-search" type="search" class="input" x-model.debounce.150ms="search" placeholder="{{ __('booking.flow.search_placeholder') }}">
                        <div class="-mx-4 mt-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]" role="tablist">
                            <button type="button" class="chip shrink-0" :class="activeCategory === null && 'chip-active'" @click="activeCategory = null" role="tab" :aria-selected="activeCategory === null">{{ __('booking.flow.all') }}</button>
                            <template x-for="c in (catalog?.categories || [])" :key="c.id">
                                <button type="button" class="chip shrink-0" :class="activeCategory === c.id && 'chip-active'" @click="activeCategory = c.id" role="tab" :aria-selected="activeCategory === c.id" x-text="c.name"></button>
                            </template>
                        </div>

                        <div class="mt-6 space-y-8">
                            <template x-for="c in visibleCategories()" :key="c.id">
                                <section>
                                    <h2 class="text-lg font-semibold" x-text="c.name"></h2>
                                    <ul class="mt-3 space-y-3">
                                        <template x-for="s in c.services" :key="s.id">
                                            <li class="card p-4 transition sm:p-5" :class="isSelected(s.id) && 'ring-2 ring-gold/60'">
                                                <div class="flex items-start justify-between gap-4">
                                                    <div class="min-w-0">
                                                        <p class="font-medium" x-text="s.name"></p>
                                                        <p class="mt-0.5 text-xs text-muted"><span x-text="s.duration"></span> {{ __('salon.ui.minutes') }} · <span x-text="money(s.price)"></span>
                                                            <template x-if="s.deposit > 0"><span class="text-gold-dark"> · {{ __('booking.flow.deposit_short') }} <span x-text="money(s.deposit)"></span></span></template></p>
                                                        <p x-show="s.description" class="mt-2 line-clamp-2 text-sm text-muted" x-text="s.description"></p>
                                                    </div>
                                                    <button type="button" class="shrink-0" :class="isSelected(s.id) ? 'btn-primary min-h-10 px-4' : 'btn-ghost min-h-10 px-4'" @click="toggleService(s)" :aria-pressed="isSelected(s.id)">
                                                        <span x-text="isSelected(s.id) ? labels.added : labels.add"></span>
                                                    </button>
                                                </div>
                                                <div x-show="isSelected(s.id) && (s.variants.length || s.addons.length)" x-transition class="mt-4 space-y-3 border-t hairline pt-4">
                                                    <div x-show="s.variants.length" role="radiogroup" :aria-label="labels.choose_option" class="flex flex-wrap gap-2">
                                                        <template x-for="v in s.variants" :key="v.id">
                                                            <button type="button" role="radio" class="chip" :class="selection(s.id)?.variant_id === v.id && 'chip-active'" :aria-checked="selection(s.id)?.variant_id === v.id" @click="setVariant(s.id, v.id)">
                                                                <span x-text="v.name"></span><span class="opacity-70" x-text="'· ' + money(v.price)"></span>
                                                            </button>
                                                        </template>
                                                    </div>
                                                    <div x-show="s.addons.length" class="flex flex-wrap gap-2">
                                                        <template x-for="a in s.addons" :key="a.id">
                                                            <label class="chip cursor-pointer" :class="selection(s.id)?.addon_ids.includes(a.id) && 'chip-active'">
                                                                <input type="checkbox" class="sr-only" :checked="selection(s.id)?.addon_ids.includes(a.id)" @change="toggleAddon(s.id, a.id)">
                                                                + <span x-text="a.name"></span><span class="opacity-70" x-text="'· ' + money(a.price)"></span>
                                                            </label>
                                                        </template>
                                                    </div>
                                                </div>
                                            </li>
                                        </template>
                                    </ul>
                                </section>
                            </template>
                            <p x-show="visibleCategories().length === 0" class="card p-8 text-center text-muted">{{ __('booking.flow.no_results') }}</p>
                        </div>
                    </div>
                </div>

                {{-- 3. Staff --}}
                <div x-show="step === 'staff'" x-transition.opacity.duration.200ms>
                    <div class="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="{{ __('booking.flow.steps.staff') }}">
                        <button type="button" role="radio" class="card-hover flex items-center gap-4 p-4 text-start" :class="staffId === null && 'ring-2 ring-gold/60'" :aria-checked="staffId === null" @click="staffId = null">
                            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-gold/15 text-xl text-gold-dark">✦</span>
                            <span><span class="block font-medium">{{ __('booking.flow.no_preference') }}</span><span class="block text-xs text-muted">{{ __('booking.flow.no_preference_sub') }}</span></span>
                        </button>
                        <template x-for="m in eligibleStaff()" :key="m.id">
                            <button type="button" role="radio" class="card-hover flex items-center gap-4 p-4 text-start" :class="staffId === m.id && 'ring-2 ring-gold/60'" :aria-checked="staffId === m.id" @click="staffId = m.id">
                                <span class="relative h-14 w-14 shrink-0 overflow-hidden rounded-full" :style="'background:' + m.color + '33'">
                                    <img x-show="m.photo" :src="m.photo" :alt="m.name" class="h-full w-full object-cover" loading="lazy">
                                    <span x-show="!m.photo" class="flex h-full w-full items-center justify-center font-display text-lg" x-text="m.initials"></span>
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate font-medium" x-text="m.name"></span>
                                    <span class="block truncate text-xs text-muted" x-text="m.title"></span>
                                    <span x-show="m.rating" class="mt-0.5 block text-xs text-gold-dark">★ <span x-text="m.rating"></span> <span class="text-muted" x-text="'(' + m.ratings_count + ')'"></span></span>
                                    <span x-show="!coversAll(m)" class="mt-0.5 block text-[11px] text-muted">{{ __('booking.flow.partial_staff') }}</span>
                                </span>
                            </button>
                        </template>
                    </div>
                </div>

                {{-- 4. Date & time --}}
                <div x-show="step === 'time'" x-transition.opacity.duration.200ms>
                    <div class="relative">
                        <div class="-mx-4 flex snap-x gap-2 overflow-x-auto px-4 pb-2 [scrollbar-width:none]" x-ref="strip" role="listbox" aria-label="{{ __('booking.flow.choose_date') }}">
                            <template x-for="d in (catalog?.dates || [])" :key="d.date">
                                <button type="button" role="option" class="flex w-16 shrink-0 snap-start flex-col items-center rounded-2xl border py-3 transition"
                                        :class="date === d.date ? 'border-ink bg-ink text-ivory' : (d.closed ? 'border-line bg-transparent text-muted/60' : 'border-line bg-white hover:border-gold')"
                                        :aria-selected="date === d.date" :aria-label="d.label" :disabled="d.closed" @click="chooseDate(d.date)" :data-date="d.date">
                                    <span class="text-[11px] uppercase tracking-wider opacity-80" x-text="d.dow"></span>
                                    <span class="font-display text-xl leading-tight" x-text="d.day"></span>
                                    <span class="text-[11px] opacity-70" x-text="d.month"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div class="mt-6" aria-live="polite" :aria-busy="loadingSlots">
                        <template x-if="loadingSlots">
                            <div class="space-y-6">
                                <template x-for="p in 3"><div><div class="skeleton h-4 w-24"></div><div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-5"><template x-for="i in 5"><div class="skeleton h-11"></div></template></div></div></template>
                            </div>
                        </template>
                        <div x-show="!loadingSlots && day">
                            <template x-for="period in ['morning', 'afternoon', 'evening']" :key="period">
                                <section x-show="day?.periods?.[period]?.length" class="mb-6">
                                    <h3 class="text-sm font-medium text-muted" x-text="labels.periods[period]"></h3>
                                    <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-5">
                                        <template x-for="slot in (day?.periods?.[period] || [])" :key="slot.starts_at">
                                            <button type="button" class="chip min-h-11 justify-center" dir="ltr" :class="selectedSlot?.starts_at === slot.starts_at && 'chip-active'" :disabled="holding" @click="chooseSlot(slot)" x-text="slot.time"></button>
                                        </template>
                                    </div>
                                </section>
                            </template>
                            <div x-show="day && day.count === 0" class="card p-8 text-center">
                                <p class="font-display text-xl">{{ __('booking.flow.no_slots') }}</p>
                                <p class="mt-2 text-sm text-muted" x-show="day?.next_available_date">{{ __('booking.flow.next_available') }} <strong x-text="day?.next_available_label"></strong></p>
                                <div class="mt-5 flex flex-wrap justify-center gap-3">
                                    <button type="button" class="btn-primary" x-show="day?.next_available_date" @click="chooseDate(day.next_available_date)">{{ __('booking.flow.jump_next') }}</button>
                                    <button type="button" class="btn-ghost" @click="joinWaitlist()" x-show="!waitlistJoined">{{ __('booking.flow.join_waitlist') }}</button>
                                    <p x-show="waitlistJoined" class="text-sm text-success">{{ __('booking.flow.waitlist_joined') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 5. Details --}}
                <div x-show="step === 'details'" x-transition.opacity.duration.200ms>
                    <div class="card space-y-5 p-6 sm:p-8">
                        <template x-if="client">
                            <div class="flex items-center justify-between gap-3 rounded-xl bg-ivory px-4 py-3 dark:bg-white/5">
                                <div><p class="font-medium" x-text="client.name"></p><p class="text-sm text-muted" dir="ltr" x-text="client.phone"></p></div>
                                <span class="text-xs text-success">✓ {{ __('booking.flow.verified') }}</span>
                            </div>
                        </template>
                        <template x-if="!client">
                            <div class="space-y-4">
                                <p class="text-sm text-muted">{{ __('booking.flow.guest_intro') }} <a class="text-gold-dark underline underline-offset-4" :href="routes.login + '?redirect=' + encodeURIComponent(location.href)">{{ __('booking.flow.sign_in') }}</a></p>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div><label class="label" for="g-name">{{ __('account.your_name') }}</label><input id="g-name" class="input" autocomplete="name" x-model="guest.name" required></div>
                                    <div><label class="label" for="g-phone">{{ __('account.phone') }}</label><input id="g-phone" class="input" type="tel" inputmode="tel" dir="ltr" autocomplete="tel" placeholder="+974 5555 1234" x-model="guest.phone" :disabled="otp.sent"></div>
                                </div>
                                <button type="button" class="btn-primary w-full sm:w-auto" x-show="!otp.sent" :disabled="busy || !guest.phone || !guest.name" @click="sendOtp()">{{ __('account.send_code') }}</button>
                                <div x-show="otp.sent" x-transition class="space-y-3">
                                    <p class="text-sm">{{ __('account.code_sent_to') }} <span dir="ltr" class="font-medium" x-text="otp.phone"></span>
                                        <button type="button" class="ms-2 text-gold-dark underline underline-offset-4" @click="otp.sent = false">{{ __('account.change') }}</button></p>
                                    <label class="label" for="g-code">{{ __('account.code') }}</label>
                                    <div class="flex gap-3">
                                        <input id="g-code" class="input text-center text-xl tracking-[0.5em]" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr" x-model="otp.code" @input="otp.code.length === 6 && verifyOtp()">
                                        <button type="button" class="btn-primary shrink-0" :disabled="busy || otp.code.length !== 6" @click="verifyOtp()">{{ __('account.verify') }}</button>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div><label class="label" for="d-email">{{ __('fields.email') }} <span class="text-muted">({{ __('salon.ui.optional') }})</span></label><input id="d-email" class="input" type="email" autocomplete="email" x-model="details.email"></div>
                        </div>
                        <div><label class="label" for="d-notes">{{ __('booking.flow.notes') }} <span class="text-muted">({{ __('salon.ui.optional') }})</span></label>
                            <textarea id="d-notes" rows="3" class="input py-3" x-model="details.notes" placeholder="{{ __('booking.flow.notes_placeholder') }}"></textarea></div>
                    </div>
                </div>

                {{-- 6. Payment --}}
                <div x-show="step === 'payment'" x-transition.opacity.duration.200ms>
                    <div class="space-y-3" role="radiogroup" aria-label="{{ __('booking.flow.steps.payment') }}">
                        <template x-for="o in (hold?.payment_options || [])" :key="o.key">
                            <button type="button" role="radio" class="card-hover flex w-full items-center justify-between gap-4 p-5 text-start" :class="payment === o.key && 'ring-2 ring-gold/60'" :aria-checked="payment === o.key" @click="payment = o.key">
                                <span><span class="block font-medium" x-text="o.label"></span><span class="block text-sm text-muted" x-text="o.hint"></span></span>
                                <span class="font-display text-lg" x-show="o.key !== 'pay_at_salon'" x-text="o.amount_label"></span>
                            </button>
                        </template>
                    </div>
                    <p class="mt-4 text-xs text-muted">{{ __('booking.flow.policy_note') }}</p>
                </div>
            </div>

            {{-- Summary (sticky on desktop, bottom sheet on mobile) --}}
            <aside class="fixed inset-x-0 bottom-0 z-30 lg:static lg:z-auto">
                <div class="glass rounded-t-3xl border-t hairline p-4 shadow-lift lg:sticky lg:top-24 lg:rounded-(--radius-card) lg:border lg:bg-white lg:p-6 lg:shadow-soft lg:dark:bg-plum">
                    <div class="hidden lg:block">
                        <p class="eyebrow">{{ __('booking.flow.summary') }}</p>
                        <p class="mt-2 font-display text-lg" x-text="branchName()"></p>
                        <ul class="mt-4 space-y-2 text-sm">
                            <template x-for="it in summaryItems()" :key="it.key">
                                <li class="flex justify-between gap-3"><span class="min-w-0 truncate" x-text="it.name"></span><span class="shrink-0 text-muted" x-text="money(it.price)"></span></li>
                            </template>
                            <li x-show="summaryItems().length === 0" class="text-muted">{{ __('booking.flow.nothing_selected') }}</li>
                        </ul>
                        <p x-show="selectedSlot" class="mt-4 rounded-xl bg-ivory px-3 py-2 text-sm dark:bg-white/5"><span x-text="day?.date_label"></span> · <span dir="ltr" x-text="selectedSlot?.time"></span>
                            <span class="block text-xs text-muted" x-show="slotStaffNames()" x-text="slotStaffNames()"></span></p>
                        <p x-show="holdRemaining() > 0" class="mt-2 text-xs text-gold-dark">{{ __('booking.flow.held_for') }} <span x-text="holdClock()"></span></p>
                    </div>
                    <div class="flex items-center gap-4 lg:mt-5 lg:block">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-2"><span class="text-xs text-muted">{{ __('booking.flow.total') }}</span><span class="font-display text-xl" x-text="money(total())"></span></div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-line" :title="duration() + ' {{ __('salon.ui.minutes') }}'">
                                <div class="flex h-full">
                                    <template x-for="(it, i) in summaryItems()" :key="it.key">
                                        <span class="h-full" :style="'width:' + Math.min(100, it.duration / 4.8) + '%;background:' + (i % 2 ? 'var(--color-rose)' : 'var(--color-gold)')"></span>
                                    </template>
                                </div>
                            </div>
                            <p class="mt-1 text-xs text-muted"><span x-text="durationLabel()"></span></p>
                        </div>
                        <button type="button" class="btn-primary shrink-0 lg:mt-4 lg:w-full" :disabled="!canContinue() || busy" @click="next()" x-show="step !== 'branch' && step !== 'time'">
                            <span x-text="step === 'payment' ? labels.confirm : labels.continue"></span>
                        </button>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    @push('scripts')
        <script>
            function bookingFlow(state) {
                return {
                    ...state,
                    labels: @js([
                        'steps' => __('booking.flow.steps'), 'periods' => __('booking.periods'), 'add' => __('booking.flow.add'), 'added' => __('booking.flow.added'),
                        'continue' => __('salon.ui.continue'), 'confirm' => __('booking.flow.confirm'), 'choose_option' => __('booking.flow.choose_option'),
                        'hours' => __('booking.flow.hours_short'), 'minutes' => __('salon.ui.minutes'), 'generic_error' => __('booking.flow.generic_error'),
                        'hold_expired' => __('booking.errors.hold_expired'),
                    ]),
                    step: 'branch', catalog: state.catalog, loadingCatalog: false, search: '', activeCategory: null,
                    selected: [], staffId: state.staffId, date: null, day: null, loadingSlots: false, selectedSlot: null,
                    hold: null, holding: false, now: Date.now(), payment: null, guest: { name: '', phone: '' },
                    otp: { sent: false, phone: '', code: '' }, details: { email: state.client?.email || '', notes: '' },
                    busy: false, error: null, waitlistJoined: false,

                    init() {
                        setInterval(() => { this.now = Date.now(); }, 1000);
                        if (this.branchId && this.catalog) {
                            this.preselect();
                            this.step = this.selected.length ? 'staff' : 'services';
                        } else if (this.branchId) {
                            this.chooseBranch(this.branchId);
                        }
                        window.addEventListener('popstate', () => this.back());
                    },
                    steps() { return (this.branches.length > 1 ? ['branch'] : []).concat(['services', 'staff', 'time', 'details', 'payment']); },
                    stepIndex() { return this.steps().indexOf(this.step); },
                    stepTitle() { return this.labels.steps[this.step]; },
                    money(v) { return window.luxe.money(v || 0, this.currency, this.locale); },
                    csrf() { return document.querySelector('meta[name=csrf-token]').content; },

                    async api(url, body = null, method = 'POST') {
                        this.error = null;
                        const opts = { method, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf(), 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' };
                        if (body && method !== 'GET') { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
                        const res = await fetch(url, opts);
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) {
                            const msg = data.message || Object.values(data.errors || {})[0]?.[0] || this.labels.generic_error;
                            throw new Error(msg);
                        }
                        return data;
                    },

                    async chooseBranch(id) {
                        if (this.branchId !== id) { this.selected = []; this.staffId = null; }
                        this.branchId = id; this.step = 'services'; this.loadingCatalog = true;
                        try {
                            this.catalog = await this.api(this.routes.catalog + '?branch=' + id, null, 'GET');
                            this.preselect();
                        } catch (e) { this.error = e.message; } finally { this.loadingCatalog = false; }
                    },
                    allServices() { return (this.catalog?.categories || []).flatMap(c => c.services).concat(this.catalog?.uncategorized || []); },
                    service(id) { return this.allServices().find(s => s.id === id); },
                    preselect() {
                        (this.serviceIds || []).forEach(id => { const s = this.service(id); if (s && !this.isSelected(id)) this.toggleService(s); });
                        this.serviceIds = [];
                        if (this.staffId && !this.catalog.staff.find(m => m.id === this.staffId)) this.staffId = null;
                    },
                    visibleCategories() {
                        const q = this.search.trim().toLowerCase();
                        return (this.catalog?.categories || [])
                            .filter(c => this.activeCategory === null || c.id === this.activeCategory)
                            .map(c => ({ ...c, services: c.services.filter(s => !q || (s.name + ' ' + (s.description || '')).toLowerCase().includes(q)) }))
                            .filter(c => c.services.length);
                    },
                    isSelected(id) { return this.selected.some(x => x.service_id === id); },
                    selection(id) { return this.selected.find(x => x.service_id === id); },
                    toggleService(s) {
                        if (this.isSelected(s.id)) { this.selected = this.selected.filter(x => x.service_id !== s.id); }
                        else { this.selected.push({ service_id: s.id, variant_id: s.variants[0]?.id || null, addon_ids: [] }); }
                        this.resetTime();
                    },
                    setVariant(id, v) { this.selection(id).variant_id = v; this.resetTime(); },
                    toggleAddon(id, a) { const sel = this.selection(id); sel.addon_ids = sel.addon_ids.includes(a) ? sel.addon_ids.filter(x => x !== a) : sel.addon_ids.concat([a]); this.resetTime(); },
                    summaryItems() {
                        return this.selected.map(sel => {
                            const s = this.service(sel.service_id); if (!s) return null;
                            const v = s.variants.find(x => x.id === sel.variant_id);
                            const addons = s.addons.filter(a => sel.addon_ids.includes(a.id));
                            const slotItem = this.selectedSlot?.items?.find(i => i.service_id === s.id);
                            return {
                                key: s.id, name: s.name + (v ? ' · ' + v.name : ''),
                                price: slotItem ? slotItem.price : (v ? v.price : s.price) + addons.reduce((t, a) => t + a.price, 0),
                                duration: slotItem ? slotItem.duration_minutes : (v ? v.duration : s.duration) + addons.reduce((t, a) => t + a.duration, 0),
                            };
                        }).filter(Boolean);
                    },
                    total() { return this.hold ? this.hold.total : this.summaryItems().reduce((t, i) => t + i.price, 0); },
                    duration() { return this.summaryItems().reduce((t, i) => t + i.duration, 0); },
                    durationLabel() { const d = this.duration(); if (!d) return ''; const h = Math.floor(d / 60), m = d % 60; return (h ? h + ' ' + this.labels.hours + ' ' : '') + (m ? m + ' ' + this.labels.minutes : ''); },
                    branchName() { return this.branches.find(b => b.id === this.branchId)?.name || ''; },
                    eligibleStaff() { const ids = this.selected.map(s => s.service_id); return (this.catalog?.staff || []).filter(m => ids.some(id => m.service_ids.includes(id))); },
                    coversAll(m) { return this.selected.every(s => m.service_ids.includes(s.service_id)); },
                    items() {
                        return this.selected.map(s => {
                            const member = this.catalog.staff.find(m => m.id === this.staffId);
                            return { ...s, staff_id: member && member.service_ids.includes(s.service_id) ? member.id : null };
                        });
                    },
                    query(extra = {}) { return new URLSearchParams({ branch: this.branchId, items: JSON.stringify(this.items()), ...extra }).toString(); },

                    resetTime() { this.day = null; this.selectedSlot = null; this.releaseHold(); },
                    releaseHold() { this.hold = null; this.payment = null; },
                    async chooseDate(d) {
                        this.date = d; this.loadingSlots = true; this.selectedSlot = null; this.waitlistJoined = false;
                        this.$nextTick(() => this.$refs.strip?.querySelector('[data-date="' + d + '"]')?.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' }));
                        try { this.day = await this.api(this.routes.availability + '?' + this.query({ date: d }), null, 'GET'); }
                        catch (e) { this.error = e.message; this.day = null; }
                        finally { this.loadingSlots = false; }
                    },
                    async chooseSlot(slot) {
                        this.holding = true; this.selectedSlot = slot;
                        try {
                            this.hold = await this.api(this.routes.hold, { branch: this.branchId, items: this.items(), starts_at: slot.starts_at });
                            this.payment = this.hold.payment_options[0]?.key || 'pay_at_salon';
                            this.go('details');
                        } catch (e) { this.error = e.message; this.selectedSlot = null; this.chooseDate(this.date); }
                        finally { this.holding = false; }
                    },
                    holdRemaining() { return this.hold ? Math.max(0, Math.floor((new Date(this.hold.expires_at) - this.now) / 1000)) : 0; },
                    holdClock() { const r = this.holdRemaining(); return Math.floor(r / 60) + ':' + String(r % 60).padStart(2, '0'); },
                    slotStaffNames() { return (this.selectedSlot?.staff_ids || []).map(id => this.day?.staff?.[id]).filter(Boolean).join(', '); },
                    async joinWaitlist() {
                        if (!this.client) { this.go('details'); this.error = @js(__('booking.errors.verify_phone')); return; }
                        try { await this.api(this.routes.waitlist, { branch: this.branchId, items: this.items(), date: this.date }); this.waitlistJoined = true; }
                        catch (e) { this.error = e.message; }
                    },

                    async sendOtp() {
                        this.busy = true;
                        try { const r = await this.api(this.routes.otpSend, { phone: this.guest.phone }); this.otp.sent = true; this.otp.phone = r.phone; }
                        catch (e) { this.error = e.message; } finally { this.busy = false; }
                    },
                    async verifyOtp() {
                        if (this.busy) return; this.busy = true;
                        try {
                            const r = await this.api(this.routes.otpVerify, { phone: this.otp.phone, code: this.otp.code, name: this.guest.name, email: this.details.email || null });
                            this.client = r.client; document.querySelector('meta[name=csrf-token]').content = r.csrf;
                        } catch (e) { this.error = e.message; } finally { this.busy = false; }
                    },

                    canContinue() {
                        switch (this.step) {
                            case 'services': return this.selected.length > 0;
                            case 'staff': return true;
                            case 'details': return !!this.client && this.holdRemaining() > 0;
                            case 'payment': return !!this.payment && this.holdRemaining() > 0;
                            default: return false;
                        }
                    },
                    go(step) { this.step = step; history.pushState({ step }, ''); window.scrollTo({ top: 0, behavior: 'smooth' }); },
                    async next() {
                        if (this.step === 'services') return this.go('staff');
                        if (this.step === 'staff') { this.go('time'); this.resetTime(); return this.chooseDate(this.date || this.catalog.dates.find(d => !d.closed)?.date); }
                        if (this.step === 'details') return this.go('payment');
                        if (this.step === 'payment') return this.checkout();
                    },
                    back() {
                        const i = this.stepIndex();
                        if (i > 0) { this.step = this.steps()[i - 1]; if (this.step === 'time' && this.date) this.chooseDate(this.date); }
                    },
                    async checkout() {
                        if (this.holdRemaining() <= 0) { this.error = this.labels.hold_expired; return this.go('time'); }
                        this.busy = true;
                        try {
                            const r = await this.api(this.routes.checkout, {
                                branch: this.branchId, items: this.items(), starts_at: this.selectedSlot.starts_at, hold_token: this.hold.token,
                                payment_preference: this.payment, notes: this.details.notes, email: this.details.email || null, name: this.guest.name || null,
                                look_reference: this.attribution.look_reference || null, utm_source: this.attribution.utm_source || null, campaign_id: this.attribution.campaign_id || null,
                            });
                            window.location.href = r.redirect;
                        } catch (e) { this.error = e.message; this.busy = false; }
                    },
                };
            }
        </script>
    @endpush
</x-layouts.site>
