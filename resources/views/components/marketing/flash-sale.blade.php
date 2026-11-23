{{--
    Live flash sale banner with countdown for the salon website.  Usage:  <x-marketing.flash-sale />
    Shows the soonest-ending live promotion of type flash_sale with "show countdown" enabled; renders nothing otherwise.
--}}
@php
    $sale = tenant()?->hasModule('marketing')
        ? \App\Domain\Marketing\Models\Promotion::query()->live()->where('type', 'flash_sale')->where('show_countdown', true)->whereNotNull('ends_at')->orderBy('ends_at')->first()
        : null;
@endphp
@if ($sale)
    <section {{ $attributes->merge(['class' => 'container-luxe py-6']) }}>
        <div class="card relative overflow-hidden px-6 py-5 sm:px-8"
             x-data="{ end: {{ $sale->ends_at->getTimestampMs() }}, now: Date.now(), get left() { return Math.max(0, this.end - this.now) } }"
             x-init="setInterval(() => now = Date.now(), 1000)">
            <div class="absolute inset-y-0 start-0 w-1 bg-gold"></div>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="eyebrow">{{ __('marketing.promotions.types.flash_sale') }}</p>
                    <p class="mt-1 font-display text-2xl font-semibold">{{ $sale->getTranslation('name', app()->getLocale()) }} · {{ $sale->discountLabel() }}</p>
                </div>
                <div class="flex items-center gap-2" aria-live="polite" dir="ltr">
                    <span class="me-2 text-sm text-muted">{{ __('marketing.promotions.ends_in') }}</span>
                    <template x-for="part in [Math.floor(left / 864e5), Math.floor(left / 36e5) % 24, Math.floor(left / 6e4) % 60, Math.floor(left / 1e3) % 60]">
                        <span class="chip min-w-12 justify-center font-mono text-base tabular-nums" x-text="String(part).padStart(2, '0')"></span>
                    </template>
                </div>
            </div>
        </div>
    </section>
@endif
