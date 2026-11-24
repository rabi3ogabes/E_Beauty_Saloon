@php
    $cfg = [
        'verified' => (bool) $client,
        'client' => $client ? ['name' => $client->name === __('account.guest') ? '' : $client->name, 'phone' => $client->phone, 'email' => $client->email, 'wallet' => (int) $client->wallet_balance, 'wallet_formatted' => money((int) $client->wallet_balance)] : null,
        'addresses' => $addresses->map(fn ($a) => ['id' => $a->id, 'label' => $a->label, 'line' => $a->oneLine(), 'zone_id' => $a->delivery_zone_id, 'lat' => $a->lat, 'lng' => $a->lng])->values(),
        'zones' => $zones->map(fn ($z) => ['id' => $z->id, 'name' => $z->name, 'areas' => $z->areas ?? [], 'same_day' => $sameDayZones->contains($z->id), 'cutoff' => $z->same_day_cutoff ?: $settings['same_day_cutoff']])->values(),
        'branches' => $pickupBranches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'lat' => $b->lat, 'lng' => $b->lng])->values(),
        'gateways' => $gateways->map(fn ($g) => ['id' => $g->id, 'label' => $g->label ?: strtoupper($g->driver)])->values(),
        'codEnabled' => (bool) $settings['cod_enabled'],
        'codOtp' => (bool) $settings['cod_otp_required'],
        'routes' => [
            'quote' => route('shop.checkout.quote'), 'otp' => route('shop.checkout.otp'), 'verify' => route('shop.checkout.otp.verify'), 'place' => route('shop.checkout.place'),
        ],
        'giftMessage' => $cart['gift_message'],
    ];
@endphp
<x-shop.shell :title="__('shop.checkout.title')" :bar="false">
    <x-slot:head>
        <meta name="robots" content="noindex">
        @vite('resources/js/shop.js')
        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('shopCheckout', (cfg) => ({
                    cfg,
                    verified: cfg.verified, phone: cfg.client?.phone || '', code: '', codeSent: false, otpBusy: false,
                    name: cfg.client?.name || '', email: cfg.client?.email || '', wallet: cfg.client?.wallet || 0, walletFormatted: cfg.client?.wallet_formatted || '',
                    fulfilment: cfg.zones.length ? 'delivery' : 'pickup',
                    zoneId: cfg.addresses[0]?.zone_id || '', branchId: cfg.branches[0]?.id || '', addressId: cfg.addresses[0]?.id || '',
                    area: '', street: '', building: '', unit: '', addressNotes: '', lat: '', lng: '', saveAddress: true, slot: '',
                    method: cfg.gateways.length ? 'online' : 'cod', codMethod: 'cash', gatewayId: cfg.gateways[0]?.id || '', useWallet: false, giftCard: '',
                    giftMessage: cfg.giftMessage || '', notes: '',
                    quote: null, quoteError: null, quoting: false, placing: false, error: null, map: null,
                    init() {
                        this.$watch('fulfilment', () => { this.refresh(); this.$nextTick(() => this.mountMap()); });
                        ['zoneId', 'branchId', 'addressId', 'slot'].forEach(k => this.$watch(k, () => this.refresh()));
                        this.$watch('area', () => this.matchArea());
                        this.refresh();
                        this.$nextTick(() => this.mountMap());
                    },
                    get newAddress() { return !this.addressId; },
                    get zone() { return this.cfg.zones.find(z => z.id == this.zoneId); },
                    matchArea() {
                        const a = this.area.trim().toLowerCase();
                        const z = this.cfg.zones.find(z => z.areas.some(x => x.toLowerCase() === a));
                        if (z) this.zoneId = z.id;
                    },
                    mountMap() {
                        if (this.fulfilment === 'pickup' || !this.newAddress || !this.$refs.map || this.map) return;
                        const go = () => {
                            this.map = window.luxeMap(this.$refs.map, { onMove: (p) => { this.lat = p.lat.toFixed(6); this.lng = p.lng.toFixed(6); this.refresh(); } });
                        };
                        window.luxeLeafletReady ? go() : window.addEventListener('luxe:leaflet', go, { once: true });
                    },
                    locate() {
                        navigator.geolocation?.getCurrentPosition((pos) => this.map?.setView(pos.coords.latitude, pos.coords.longitude));
                    },
                    payload() {
                        return {
                            fulfilment: this.fulfilment, branch_id: this.branchId, address_id: this.fulfilment === 'pickup' ? null : (this.addressId || null),
                            zone_id: this.zoneId || null, area: this.area, street: this.street, building: this.building, unit: this.unit, address_notes: this.addressNotes,
                            lat: this.lat, lng: this.lng, save_address: this.saveAddress, delivery_slot: this.fulfilment === 'same_day' ? this.slot : null,
                            customer_name: this.name, customer_email: this.email, payment_method: this.method, cod_method: this.codMethod,
                            gateway_id: this.gatewayId, use_wallet: this.useWallet, gift_card_code: this.giftCard, gift_message: this.giftMessage, notes: this.notes,
                        };
                    },
                    needsArea() { return this.fulfilment !== 'pickup' && !this.addressId && !this.zoneId && !(this.lat && this.lng); },
                    refresh() {
                        clearTimeout(this._q);
                        // Delivery with no area yet: wait quietly for the client to pick one instead of showing an error.
                        if (this.needsArea()) { this.quote = null; this.quoteError = null; return; }
                        this._q = setTimeout(async () => {
                            this.quoting = true;
                            try { this.quote = await window.luxeShopApi(this.cfg.routes.quote, 'POST', this.payload()); this.quoteError = null; if (this.quote.slots?.length && !this.quote.slots.includes(this.slot)) this.slot = this.quote.slots[0]; }
                            catch (e) { this.quoteError = e.message; }
                            this.quoting = false;
                        }, 200);
                    },
                    async sendCode() {
                        this.otpBusy = true; this.error = null;
                        try { const r = await window.luxeShopApi(this.cfg.routes.otp, 'POST', { phone: this.phone }); this.phone = r.phone; this.codeSent = true; this.$nextTick(() => this.$refs.code?.focus()); }
                        catch (e) { this.error = e.data?.errors?.phone?.[0] || e.message; }
                        this.otpBusy = false;
                    },
                    async verify() {
                        this.otpBusy = true; this.error = null;
                        try {
                            const r = await window.luxeShopApi(this.cfg.routes.verify, 'POST', { phone: this.phone, code: this.code, name: this.name });
                            this.verified = true; this.name = this.name || r.client.name; this.email = this.email || r.client.email || '';
                            this.wallet = r.client.wallet; this.walletFormatted = r.client.wallet_formatted;
                            if (r.csrf) document.querySelector('meta[name=csrf-token]')?.setAttribute('content', r.csrf);
                            this.refresh();
                        } catch (e) { this.error = e.data?.errors?.code?.[0] || e.message; }
                        this.otpBusy = false;
                    },
                    async place() {
                        if (!this.verified) { this.error = @js(__('shop.errors.verify_phone')); return; }
                        this.placing = true; this.error = null;
                        try { const r = await window.luxeShopApi(this.cfg.routes.place, 'POST', this.payload()); window.location = r.redirect; }
                        catch (e) { this.error = e.data?.errors ? Object.values(e.data.errors)[0][0] : e.message; this.placing = false; }
                    },
                }));
            });
        </script>
    </x-slot:head>

    <section class="container-luxe py-10 sm:py-14" x-data="shopCheckout(@js($cfg))">
        <a href="{{ route('shop.index') }}" class="text-sm text-muted hover:text-ink">← {{ __('shop.cart.continue') }}</a>
        <h1 class="mt-3 text-3xl font-semibold sm:text-4xl">{{ __('shop.checkout.title') }}</h1>

        <div class="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_380px]">
            <div class="space-y-6">
                {{-- 1. Contact (inline phone OTP for guests) --}}
                <section class="card p-6 sm:p-8">
                    <h2 class="flex items-center gap-3 text-xl font-semibold"><span class="flex h-7 w-7 items-center justify-center rounded-full bg-ink text-xs text-ivory">1</span>{{ __('shop.checkout.contact') }}</h2>
                    <div class="mt-5 space-y-4">
                        <template x-if="verified">
                            <p class="flex items-center gap-2 rounded-xl bg-success/10 px-4 py-3 text-sm text-success">✓ <span x-text="@js(__('shop.checkout.verified_as', ['name' => '__N__'])).replace('__N__', name || phone)"></span> <span dir="ltr" class="text-muted" x-text="phone"></span></p>
                        </template>
                        <div x-show="!verified" class="space-y-3">
                            <div class="flex gap-2">
                                <div class="flex-1">
                                    <label for="co-phone" class="label">{{ __('shop.checkout.phone') }}</label>
                                    <input id="co-phone" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" class="input" x-model="phone" placeholder="+974 5555 1234" :disabled="codeSent">
                                </div>
                                <button type="button" class="btn-ghost mt-6 shrink-0" @click="codeSent ? (codeSent = false) : sendCode()" :disabled="otpBusy || phone.length < 8" x-text="codeSent ? @js(__('shop.checkout.change')) : @js(__('shop.checkout.send_code'))"></button>
                            </div>
                            <p class="text-xs text-muted" x-show="!codeSent">{{ __('shop.checkout.phone_hint') }}</p>
                            <div x-show="codeSent" x-cloak class="flex gap-2">
                                <div class="flex-1">
                                    <label for="co-code" class="label">{{ __('shop.checkout.code') }}</label>
                                    <input id="co-code" x-ref="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr" class="input tracking-[0.5em]" x-model="code" @keydown.enter.prevent="verify()">
                                </div>
                                <button type="button" class="btn-primary mt-6 shrink-0" @click="verify()" :disabled="otpBusy || code.length !== 6">{{ __('shop.checkout.verify') }}</button>
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div><label for="co-name" class="label">{{ __('shop.checkout.name') }}</label><input id="co-name" autocomplete="name" class="input" x-model="name" required></div>
                            <div><label for="co-email" class="label">{{ __('shop.checkout.email') }}</label><input id="co-email" type="email" autocomplete="email" class="input" x-model="email"></div>
                        </div>
                    </div>
                </section>

                {{-- 2. Fulfilment --}}
                <section class="card p-6 sm:p-8">
                    <h2 class="flex items-center gap-3 text-xl font-semibold"><span class="flex h-7 w-7 items-center justify-center rounded-full bg-ink text-xs text-ivory">2</span>{{ __('shop.checkout.fulfilment') }}</h2>
                    <div class="mt-5 grid gap-3 sm:grid-cols-3" role="radiogroup">
                        @if ($zones->isNotEmpty())
                            <button type="button" role="radio" :aria-checked="fulfilment === 'delivery'" @click="fulfilment = 'delivery'" class="rounded-2xl border p-4 text-start transition" :class="fulfilment === 'delivery' ? 'border-ink bg-ivory dark:border-gold dark:bg-white/5' : 'hairline hover:border-gold'">
                                <p class="font-medium">{{ __('shop.checkout.delivery') }}</p><p class="mt-1 text-xs text-muted">{{ __('shop.checkout.delivery_hint') }}</p>
                            </button>
                            <button type="button" role="radio" :aria-checked="fulfilment === 'same_day'" @click="fulfilment = 'same_day'" :disabled="!cfg.zones.some(z => z.same_day)" class="rounded-2xl border p-4 text-start transition disabled:opacity-40" :class="fulfilment === 'same_day' ? 'border-ink bg-ivory dark:border-gold dark:bg-white/5' : 'hairline hover:border-gold'">
                                <p class="font-medium">{{ __('shop.checkout.same_day') }}</p><p class="mt-1 text-xs text-muted" x-text="@js(__('shop.checkout.same_day_hint', ['time' => '__T__'])).replace('__T__', (zone || cfg.zones.find(z => z.same_day))?.cutoff || '')"></p>
                            </button>
                        @endif
                        @if ($pickupBranches->isNotEmpty())
                            <button type="button" role="radio" :aria-checked="fulfilment === 'pickup'" @click="fulfilment = 'pickup'" class="rounded-2xl border p-4 text-start transition" :class="fulfilment === 'pickup' ? 'border-ink bg-ivory dark:border-gold dark:bg-white/5' : 'hairline hover:border-gold'">
                                <p class="font-medium">{{ __('shop.checkout.pickup') }}</p><p class="mt-1 text-xs text-muted">{{ __('shop.checkout.pickup_hint') }}</p>
                            </button>
                        @endif
                    </div>
                    @if ($zones->isEmpty())<p class="mt-4 text-sm text-muted">{{ __('shop.checkout.no_zones') }}</p>@endif

                    {{-- Pickup --}}
                    <div x-show="fulfilment === 'pickup'" x-cloak class="mt-6 space-y-3">
                        <p class="label">{{ __('shop.checkout.choose_branch') }}</p>
                        <template x-for="b in cfg.branches" :key="b.id">
                            <label class="flex cursor-pointer items-center justify-between rounded-xl border px-4 py-3 transition" :class="branchId == b.id ? 'border-ink dark:border-gold' : 'hairline'">
                                <span class="flex items-center gap-3"><input type="radio" class="accent-ink" :value="b.id" x-model="branchId"><span x-text="b.name"></span></span>
                                <span class="text-xs text-muted" x-show="branchId == b.id && quote?.ready_estimate" x-text="@js(__('shop.checkout.ready_by', ['time' => '__T__'])).replace('__T__', quote?.ready_estimate)"></span>
                            </label>
                        </template>
                    </div>

                    {{-- Delivery / same-day --}}
                    <div x-show="fulfilment !== 'pickup'" x-cloak class="mt-6 space-y-4">
                        <template x-if="cfg.addresses.length">
                            <div class="space-y-2">
                                <p class="label">{{ __('shop.checkout.saved_addresses') }}</p>
                                <template x-for="a in cfg.addresses" :key="a.id">
                                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-3" :class="addressId == a.id ? 'border-ink dark:border-gold' : 'hairline'">
                                        <input type="radio" class="mt-1 accent-ink" :value="a.id" x-model="addressId" @change="zoneId = a.zone_id || zoneId"><span><span class="font-medium" x-text="a.label"></span><span class="block text-sm text-muted" x-text="a.line"></span></span>
                                    </label>
                                </template>
                                <label class="flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3" :class="!addressId ? 'border-ink dark:border-gold' : 'hairline'">
                                    <input type="radio" class="accent-ink" value="" x-model="addressId" @change="$nextTick(() => mountMap())">{{ __('shop.checkout.new_address') }}
                                </label>
                            </div>
                        </template>
                        <div>
                            <label for="co-zone" class="label">{{ __('shop.checkout.zone') }}</label>
                            <select id="co-zone" class="input" x-model="zoneId">
                                <option value="">{{ __('shop.checkout.choose_zone') }}</option>
                                <template x-for="z in cfg.zones" :key="z.id"><option :value="z.id" x-text="z.name" :selected="zoneId == z.id"></option></template>
                            </select>
                        </div>
                        <div x-show="newAddress" class="space-y-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div><label for="co-area" class="label">{{ __('shop.checkout.area') }}</label><input id="co-area" class="input" x-model.lazy="area" list="co-areas" autocomplete="address-level3"></div>
                                <div><label for="co-street" class="label">{{ __('shop.checkout.street') }}</label><input id="co-street" class="input" x-model="street" autocomplete="address-line1"></div>
                                <div><label for="co-building" class="label">{{ __('shop.checkout.building') }}</label><input id="co-building" class="input" x-model="building"></div>
                                <div><label for="co-unit" class="label">{{ __('shop.checkout.unit') }}</label><input id="co-unit" class="input" x-model="unit" autocomplete="address-line2"></div>
                            </div>
                            <datalist id="co-areas">@foreach ($zones->flatMap(fn ($z) => $z->areas ?? [])->unique() as $a)<option value="{{ $a }}">@endforeach</datalist>
                            <div>
                                <div class="mb-2 flex items-center justify-between"><p class="text-xs text-muted">{{ __('shop.checkout.pin_hint') }}</p><button type="button" class="text-xs text-gold-dark underline underline-offset-4" @click="locate()">{{ __('shop.checkout.use_location') }}</button></div>
                                <div x-ref="map" class="h-64 overflow-hidden rounded-2xl bg-ivory ring-1 ring-line" role="application" aria-label="{{ __('shop.checkout.pin_hint') }}"><div class="skeleton h-full w-full"></div></div>
                            </div>
                            <div><label for="co-notes" class="label">{{ __('shop.checkout.address_notes') }}</label><input id="co-notes" class="input" x-model="addressNotes"></div>
                            <label class="flex items-center gap-2 text-sm" x-show="verified"><input type="checkbox" class="accent-ink" x-model="saveAddress"> {{ __('shop.checkout.save_address') }}</label>
                        </div>
                        <div x-show="fulfilment === 'same_day'">
                            <label for="co-slot" class="label">{{ __('shop.checkout.slot') }}</label>
                            <select id="co-slot" class="input" x-model="slot">
                                <option value="">{{ __('shop.checkout.choose_slot') }}</option>
                                <template x-for="s in (quote?.slots || [])" :key="s"><option :value="s" x-text="s.split(' ')[1]" :selected="slot === s"></option></template>
                            </select>
                        </div>
                    </div>
                </section>

                {{-- 3. Payment --}}
                <section class="card p-6 sm:p-8">
                    <h2 class="flex items-center gap-3 text-xl font-semibold"><span class="flex h-7 w-7 items-center justify-center rounded-full bg-ink text-xs text-ivory">3</span>{{ __('shop.checkout.payment') }}</h2>
                    <div class="mt-5 space-y-3">
                        <template x-if="cfg.gateways.length">
                            <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-4" :class="method === 'online' ? 'border-ink dark:border-gold' : 'hairline'">
                                <input type="radio" value="online" x-model="method" class="mt-1 accent-ink">
                                <span class="flex-1"><span class="font-medium">{{ __('shop.checkout.online') }}</span>
                                    <span class="block text-xs text-muted" x-text="@js(__('shop.checkout.online_hint', ['gateway' => '__G__'])).replace('__G__', cfg.gateways.map(g => g.label).join(' · '))"></span>
                                    <select x-show="method === 'online' && cfg.gateways.length > 1" x-model="gatewayId" class="input mt-3" aria-label="{{ __('shop.checkout.payment') }}"><template x-for="g in cfg.gateways" :key="g.id"><option :value="g.id" x-text="g.label"></option></template></select>
                                </span>
                            </label>
                        </template>
                        <template x-if="cfg.codEnabled">
                            <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-4" :class="method === 'cod' ? 'border-ink dark:border-gold' : 'hairline'">
                                <input type="radio" value="cod" x-model="method" class="mt-1 accent-ink">
                                <span class="flex-1"><span class="font-medium">{{ __('shop.checkout.cod') }}</span><span class="block text-xs text-muted">{{ __('shop.checkout.cod_hint') }}</span>
                                    <span x-show="method === 'cod'" class="mt-3 flex gap-2">
                                        <label class="chip cursor-pointer has-checked:chip-active"><input type="radio" class="sr-only" value="cash" x-model="codMethod">{{ __('shop.checkout.cod_cash') }}</label>
                                        <label class="chip cursor-pointer has-checked:chip-active"><input type="radio" class="sr-only" value="card_terminal" x-model="codMethod">{{ __('shop.checkout.cod_card') }}</label>
                                    </span>
                                    <span x-show="method === 'cod' && cfg.codOtp" class="mt-2 block text-xs text-muted">{{ __('shop.checkout.cod_otp_note') }}</span>
                                </span>
                            </label>
                        </template>
                        <label x-show="wallet > 0" class="flex items-center gap-3 rounded-2xl border hairline p-4 text-sm"><input type="checkbox" class="accent-ink" x-model="useWallet"><span x-text="@js(__('shop.checkout.wallet', ['balance' => '__B__'])).replace('__B__', walletFormatted)"></span></label>
                        <div>
                            <label for="co-gift" class="label">{{ __('shop.checkout.gift_card') }}</label>
                            <input id="co-gift" class="input uppercase" x-model="giftCard" placeholder="{{ __('shop.checkout.gift_card_placeholder') }}" autocomplete="off">
                        </div>
                        <div><label for="co-order-notes" class="label">{{ __('shop.checkout.order_notes') }}</label><textarea id="co-order-notes" rows="2" class="input py-2" x-model="notes"></textarea></div>
                    </div>
                </section>
            </div>

            {{-- Summary --}}
            <aside class="lg:sticky lg:top-6 lg:self-start">
                <div class="card p-6">
                    <h2 class="text-lg font-semibold">{{ __('shop.checkout.summary') }}</h2>
                    <ul class="mt-4 space-y-3">
                        @foreach ($cart['items'] as $item)
                            <li class="flex gap-3 text-sm">
                                <span class="relative h-14 w-14 shrink-0 overflow-hidden rounded-lg bg-gradient-to-br from-rose to-ivory">@if ($item['image'])<img src="{{ $item['image'] }}" alt="" class="h-full w-full object-cover">@endif
                                    <span class="absolute -end-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-ink px-1 text-[10px] text-ivory">{{ $item['qty'] }}</span></span>
                                <span class="flex-1"><span class="line-clamp-2">{{ $item['name'] }}</span>@if ($item['variant'])<span class="text-xs text-muted">{{ $item['variant'] }}</span>@endif</span>
                                <span>{{ $item['line_total_formatted'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-5 border-t hairline pt-4">
                        <template x-if="!quote && quoting"><div class="space-y-2"><div class="skeleton h-4"></div><div class="skeleton h-4 w-2/3"></div><div class="skeleton h-6"></div></div></template>
                        <dl class="space-y-1.5 text-sm" x-show="quote" :class="quoting ? 'opacity-60' : ''">
                            <div class="flex justify-between"><dt class="text-muted">{{ __('shop.cart.subtotal') }}</dt><dd x-text="quote?.formatted.subtotal"></dd></div>
                            <div class="flex justify-between text-success" x-show="quote?.discount > 0"><dt>{{ __('shop.cart.discount') }} <span class="font-mono text-xs" x-text="quote?.coupon"></span></dt><dd x-text="'−' + quote?.formatted.discount"></dd></div>
                            <div class="flex justify-between" x-show="quote?.fulfilment !== 'pickup'"><dt class="text-muted">{{ __('shop.checkout.delivery_fee') }}</dt><dd x-text="quote?.delivery_fee > 0 ? quote?.formatted.delivery_fee : @js(__('shop.checkout.free'))"></dd></div>
                            <div class="flex justify-between" x-show="quote?.gift_wrap_fee > 0"><dt class="text-muted">{{ __('shop.cart.gift_wrap') }}</dt><dd x-text="quote?.formatted.gift_wrap_fee"></dd></div>
                            <div class="flex justify-between" x-show="quote?.tax > 0 && !quote?.tax_inclusive"><dt class="text-muted">{{ __('shop.checkout.tax') }}</dt><dd x-text="quote?.formatted.tax"></dd></div>
                            <div class="flex justify-between border-t hairline pt-3 text-base font-semibold"><dt>{{ __('shop.checkout.total') }}</dt><dd x-text="quote?.formatted.total"></dd></div>
                            <p class="text-xs text-muted" x-show="quote?.tax > 0 && quote?.tax_inclusive">{{ __('shop.checkout.tax_included') }}</p>
                        </dl>
                        <p class="mt-3 rounded-xl bg-gold/10 px-3 py-2 text-xs text-gold-dark" x-show="quote?.free_shipping_gap > 0" x-text="@js(__('shop.checkout.free_shipping_gap', ['amount' => '__A__'])).replace('__A__', luxe.money(quote?.free_shipping_gap || 0))"></p>
                        <p class="mt-3 text-xs text-muted" x-show="quote?.ready_estimate && quote?.fulfilment !== 'same_day'" x-text="(quote?.fulfilment === 'pickup' ? @js(__('shop.checkout.ready_by', ['time' => '__T__'])) : @js(__('shop.checkout.eta', ['time' => '__T__']))).replace('__T__', quote?.ready_estimate)"></p>
                        <p class="mt-3 rounded-xl bg-danger/10 px-3 py-2 text-sm text-danger" x-show="quoteError" x-text="quoteError" role="alert"></p>
                        <p class="mt-3 rounded-xl bg-gold/10 px-3 py-2 text-sm text-gold-dark" x-show="!quoteError && needsArea()" x-cloak>{{ __('shop.checkout.choose_area') }}</p>
                    </div>
                    <p class="mt-4 rounded-xl bg-danger/10 px-3 py-2 text-sm text-danger" x-show="error" x-text="error" role="alert"></p>
                    <button type="button" class="btn-primary mt-5 h-12 w-full" @click="place()" :disabled="placing || !!quoteError || !quote">
                        <span x-show="!placing" x-text="method === 'online' ? @js(__('shop.checkout.pay_and_place')) : @js(__('shop.checkout.place_order'))"></span>
                        <span x-show="placing" x-cloak>{{ __('shop.checkout.placing') }}</span>
                    </button>
                    <p class="mt-3 text-center text-[11px] text-muted">🔒 {{ __('shop.checkout.secure_note') }}</p>
                </div>
            </aside>
        </div>
    </section>
</x-shop.shell>
