{{-- Slide-over bag. State lives in Alpine.store('cart'). --}}
<div x-data x-cloak>
    {{-- Toast --}}
    <div x-show="$store.cart.toast" x-transition.opacity.duration.200ms class="fixed inset-x-0 bottom-6 z-[70] flex justify-center px-4" role="status" aria-live="polite">
        <p class="glass rounded-full px-5 py-3 text-sm shadow-lift" :class="$store.cart.toast?.error ? 'text-danger' : ''" x-text="$store.cart.toast?.msg"></p>
    </div>

    <div x-show="$store.cart.open" x-transition.opacity.duration.200ms class="fixed inset-0 z-[60] bg-ink/30 backdrop-blur-sm" @click="$store.cart.open = false" aria-hidden="true"></div>
    <aside x-show="$store.cart.open" @keydown.escape.window="$store.cart.open = false"
           x-transition:enter="transition duration-250 ease-out" x-transition:enter-start="ltr:translate-x-full rtl:-translate-x-full" x-transition:enter-end="translate-x-0"
           x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="ltr:translate-x-full rtl:-translate-x-full"
           class="fixed inset-y-0 end-0 z-[61] flex w-full max-w-md flex-col bg-ivory shadow-lift dark:bg-night" role="dialog" aria-modal="true" aria-labelledby="cart-title">
        <header class="flex items-center justify-between border-b hairline px-6 py-5">
            <div>
                <h2 id="cart-title" class="text-xl font-semibold">{{ __('shop.cart.title') }}</h2>
                <p class="text-xs text-muted" x-show="$store.cart.data.count" x-text="@js(__('shop.cart.items', ['count' => '__N__'])).replace('__N__', $store.cart.data.count)"></p>
            </div>
            <button type="button" class="chip min-h-11 px-3" @click="$store.cart.open = false" aria-label="{{ __('shop.cart.close') }}">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </header>

        <div class="flex-1 overflow-y-auto px-6 py-5">
            <template x-if="$store.cart.loading && !$store.cart.data.items.length">
                <div class="space-y-4">@for ($i = 0; $i < 3; $i++)<div class="flex gap-4"><div class="skeleton h-20 w-20"></div><div class="flex-1 space-y-2"><div class="skeleton h-4 w-3/4"></div><div class="skeleton h-4 w-1/3"></div></div></div>@endfor</div>
            </template>

            <template x-if="!$store.cart.loading && !$store.cart.data.items.length">
                <div class="flex h-full flex-col items-center justify-center py-16 text-center">
                    <span class="flex h-16 w-16 items-center justify-center rounded-full bg-rose/50 text-2xl text-gold-dark">✦</span>
                    <p class="mt-5 font-display text-xl">{{ __('shop.cart.empty') }}</p>
                    <p class="mt-2 text-sm text-muted">{{ __('shop.cart.empty_sub') }}</p>
                    <a href="{{ route('shop.index') }}" class="btn-primary mt-6">{{ __('shop.cart.empty_cta') }}</a>
                </div>
            </template>

            <ul class="divide-y hairline" x-show="$store.cart.data.items.length">
                <template x-for="item in $store.cart.data.items" :key="item.id">
                    <li class="flex gap-4 py-4">
                        <a :href="item.url" class="h-20 w-20 shrink-0 overflow-hidden rounded-xl bg-gradient-to-br from-rose to-ivory">
                            <img x-show="item.image" :src="item.image" alt="" class="h-full w-full object-cover" loading="lazy">
                        </a>
                        <div class="min-w-0 flex-1">
                            <p class="eyebrow !text-[10px]" x-text="item.brand"></p>
                            <a :href="item.url" class="block truncate font-medium" x-text="item.name"></a>
                            <p class="flex items-center gap-1.5 text-xs text-muted" x-show="item.variant">
                                <span x-show="item.swatch" class="h-3 w-3 rounded-full ring-1 ring-line" :style="`background:${item.swatch}`"></span><span x-text="item.variant"></span>
                            </p>
                            <div class="mt-2 flex items-center justify-between">
                                <div class="flex items-center rounded-full border hairline" role="group" :aria-label="@js(__('shop.cart.qty'))">
                                    <button type="button" class="flex h-9 w-9 items-center justify-center" @click="$store.cart.update(item.id, item.qty - 1)" :disabled="$store.cart.busy" aria-label="{{ __('shop.cart.decrease') }}">−</button>
                                    <span class="w-6 text-center text-sm" x-text="item.qty"></span>
                                    <button type="button" class="flex h-9 w-9 items-center justify-center" @click="$store.cart.update(item.id, item.qty + 1)" :disabled="$store.cart.busy" aria-label="{{ __('shop.cart.increase') }}">+</button>
                                </div>
                                <p class="text-sm font-medium" x-text="item.line_total_formatted"></p>
                            </div>
                        </div>
                    </li>
                </template>
            </ul>

            {{-- Complete the routine --}}
            <section class="mt-6" x-show="$store.cart.data.upsell?.length">
                <h3 class="font-display text-lg">{{ __('shop.cart.routine_title') }}</h3>
                <p class="text-xs text-muted">{{ __('shop.cart.routine_sub') }}</p>
                <div class="mt-3 grid grid-cols-3 gap-3">
                    <template x-for="p in $store.cart.data.upsell" :key="p.id">
                        <div class="text-center">
                            <a :href="p.url" class="block aspect-square overflow-hidden rounded-xl bg-gradient-to-br from-rose to-ivory"><img x-show="p.image" :src="p.image" alt="" class="h-full w-full object-cover" loading="lazy"></a>
                            <p class="mt-1.5 line-clamp-2 text-xs" x-text="p.name"></p>
                            <button type="button" class="mt-1 text-xs font-medium text-gold-dark underline underline-offset-4" @click="$store.cart.add(p.variant_id, 1, false)" x-text="p.price"></button>
                        </div>
                    </template>
                </div>
            </section>

            {{-- Gift wrap & coupon --}}
            <section class="mt-6 space-y-4" x-show="$store.cart.data.items.length" x-data="{ wrap: false, msg: '' }" x-effect="wrap = $store.cart.data.gift_wrap; msg = $store.cart.data.gift_message || ''">
                @if (\App\Domain\Shop\Support\ShopSettings::get('gift_wrap_enabled'))
                    <div class="card p-4">
                        <label class="flex cursor-pointer items-center gap-3 text-sm">
                            <input type="checkbox" class="h-4 w-4 accent-ink" x-model="wrap" @change="$store.cart.saveGift(wrap, msg)">
                            {{ __('shop.cart.gift_wrap_label', ['price' => money((int) \App\Domain\Shop\Support\ShopSettings::get('gift_wrap_price'))]) }}
                        </label>
                        <div x-show="wrap" x-transition.opacity.duration.200ms class="mt-3">
                            <label for="gift-msg" class="label">{{ __('shop.cart.gift_message') }}</label>
                            <textarea id="gift-msg" x-model="msg" @change="$store.cart.saveGift(wrap, msg)" maxlength="300" rows="2" class="input py-2" placeholder="{{ __('shop.cart.gift_message_placeholder') }}"></textarea>
                        </div>
                    </div>
                @endif
                <div>
                    <template x-if="$store.cart.data.coupon && !$store.cart.data.coupon.error">
                        <div class="flex items-center justify-between rounded-xl bg-success/10 px-4 py-3 text-sm text-success">
                            <span><span class="font-mono tracking-wider" x-text="$store.cart.data.coupon.code"></span> ✓</span>
                            <button type="button" class="underline underline-offset-4" @click="$store.cart.removeCoupon()">{{ __('shop.cart.remove') }}</button>
                        </div>
                    </template>
                    <form x-show="!$store.cart.data.coupon || $store.cart.data.coupon.error" class="flex gap-2" @submit.prevent="$store.cart.applyCoupon()">
                        <label for="coupon" class="sr-only">{{ __('shop.cart.coupon') }}</label>
                        <input id="coupon" x-model="$store.cart.couponCode" class="input uppercase" placeholder="{{ __('shop.cart.coupon_placeholder') }}" autocomplete="off">
                        <button class="btn-ghost shrink-0" :disabled="$store.cart.busy">{{ __('shop.cart.apply') }}</button>
                    </form>
                    <p class="mt-1 text-xs text-danger" x-show="$store.cart.data.coupon?.error" x-text="$store.cart.data.coupon?.error"></p>
                </div>
            </section>
        </div>

        <footer class="border-t hairline px-6 py-5" x-show="$store.cart.data.items.length">
            <dl class="space-y-1.5 text-sm">
                <div class="flex justify-between"><dt class="text-muted">{{ __('shop.cart.subtotal') }}</dt><dd x-text="$store.cart.data.formatted.subtotal"></dd></div>
                <div class="flex justify-between text-success" x-show="$store.cart.data.discount > 0"><dt>{{ __('shop.cart.discount') }}</dt><dd x-text="'−' + $store.cart.data.formatted.discount"></dd></div>
                <div class="flex justify-between" x-show="$store.cart.data.gift_wrap_fee > 0"><dt class="text-muted">{{ __('shop.cart.gift_wrap') }}</dt><dd x-text="$store.cart.data.formatted.gift_wrap"></dd></div>
                <div class="flex justify-between pt-2 text-base font-semibold"><dt>{{ __('shop.cart.total') }}</dt><dd x-text="$store.cart.data.formatted.total"></dd></div>
            </dl>
            <p class="mt-1 text-xs text-muted">{{ __('shop.cart.taxes_note') }}</p>
            <a href="{{ route('shop.checkout') }}" class="btn-primary mt-4 w-full">{{ __('shop.cart.checkout') }}</a>
            <button type="button" class="mt-2 w-full py-2 text-sm text-muted underline-offset-4 hover:underline" @click="$store.cart.open = false">{{ __('shop.cart.continue') }}</button>
        </footer>
    </aside>
</div>
