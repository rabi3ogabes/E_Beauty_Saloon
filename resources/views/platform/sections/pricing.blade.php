@php
    $planData = $plans->map(fn ($p) => [
        'slug' => $p->slug, 'name' => $p->name, 'tagline' => $p->tagline, 'monthly' => money($p->monthly_price, $p->currency),
        'included' => $p->included_modules ?? [], 'branches' => $p->included_branches, 'featured' => (bool) $p->is_featured,
        'credits' => (int) $p->included_ai_credits, 'staff' => $p->included_staff,
    ])->values();
    $moduleData = $modules->map(fn ($m) => ['key' => $m->key, 'name' => $m->name])->values();
    $discounts = \App\Domain\Billing\Models\BillingCycle::whereNull('plan_id')->pluck('discount_percent', 'months');
@endphp
<section id="pricing" class="site-section">
    <div class="container-luxe">
        <div class="mx-auto max-w-2xl text-center">
            <p class="eyebrow">{{ __('platform.nav.pricing') }}</p>
            <h2 class="mt-3 text-3xl font-semibold sm:text-5xl">{{ $block->t('title') }}</h2>
            @if ($block->t('subtitle'))<p class="mt-4 text-muted">{{ $block->t('subtitle') }}</p>@endif
        </div>

        <div x-data="luxePricing(@js(['plans' => $planData, 'modules' => $moduleData, 'url' => route('platform.pricing.quote'), 'signup' => url('/signup')]))" x-init="refresh()" class="mt-12">
            {{-- Cycle toggle --}}
            <div class="flex justify-center">
                <div class="inline-flex flex-wrap justify-center gap-1 rounded-full border border-line bg-white p-1" role="radiogroup" aria-label="{{ __('platform.pricing.cycle') }}">
                    @foreach (config('salon.billing_cycles') as $months)
                        <button type="button" role="radio" :aria-checked="cycle === {{ $months }}" @click="cycle = {{ $months }}; refresh()"
                                class="min-h-10 rounded-full px-4 text-sm transition" :class="cycle === {{ $months }} ? 'bg-ink text-ivory' : 'text-muted hover:text-ink'">
                            {{ trans_choice('platform.pricing.months', $months, ['count' => $months]) }}
                            @if (($discounts[$months] ?? 0) > 0)<span class="ms-1 text-[11px] text-gold-dark" :class="cycle === {{ $months }} && 'text-gold'">−{{ rtrim(rtrim(number_format($discounts[$months], 1), '0'), '.') }}%</span>@endif
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Plans --}}
            <div class="mt-10 grid gap-5 lg:grid-cols-3">
                <template x-for="p in plans" :key="p.slug">
                    <button type="button" @click="plan = p.slug; refresh()" class="card relative flex flex-col p-7 text-start transition" :class="plan === p.slug ? 'ring-2 ring-gold shadow-lift' : 'hover:shadow-lift'">
                        <span x-show="p.featured" class="absolute -top-3 end-6 rounded-full bg-gold px-3 py-1 text-[11px] font-semibold text-ink">{{ __('platform.pricing.popular') }}</span>
                        <span class="font-display text-2xl" x-text="p.name"></span>
                        <span class="mt-1 text-sm text-muted" x-text="p.tagline"></span>
                        <span class="mt-6"><span class="font-display text-4xl" x-text="p.monthly"></span> <span class="text-sm text-muted">{{ __('salon.ui.per_month') }}</span></span>
                        <ul class="mt-6 space-y-2 text-sm">
                            <li>✓ <span x-text="p.branches"></span> {{ __('platform.pricing.branches_included') }}</li>
                            <li>✓ <span x-text="p.staff"></span> {{ __('platform.pricing.staff_included') }}</li>
                            <li x-show="p.credits > 0">✓ <span x-text="p.credits"></span> {{ __('platform.pricing.ai_credits') }}</li>
                            <template x-for="m in modules.filter(m => p.included.includes(m.key))" :key="m.key"><li>✓ <span x-text="m.name"></span></li></template>
                        </ul>
                    </button>
                </template>
            </div>

            {{-- Configurator --}}
            <div class="mt-10 grid gap-6 lg:grid-cols-[1.4fr_1fr]">
                <div class="card p-6 sm:p-8">
                    <label class="label" for="branches-range">{{ __('platform.pricing.branches') }}: <span class="font-semibold text-ink" x-text="branches"></span></label>
                    <input id="branches-range" type="range" min="1" max="20" x-model.number="branches" @change="refresh()" @input.debounce.250ms="refresh()" class="w-full accent-[#C8A96A]">
                    <p class="mt-8 label">{{ __('platform.pricing.add_modules') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <template x-for="m in modules" :key="m.key">
                            <label class="chip min-h-11 cursor-pointer" :class="(included(m.key) || extras.includes(m.key)) && 'chip-active'">
                                <input type="checkbox" class="sr-only" :value="m.key" :disabled="included(m.key)" :checked="included(m.key) || extras.includes(m.key)" @change="toggle(m.key)">
                                <span x-text="m.name"></span><span x-show="included(m.key)" class="text-[11px] opacity-70">· {{ __('platform.pricing.included') }}</span>
                            </label>
                        </template>
                    </div>
                </div>
                <div class="card flex flex-col p-6 sm:p-8" aria-live="polite">
                    <p class="label">{{ __('platform.pricing.your_price') }}</p>
                    <template x-if="!quote"><div class="skeleton mt-2 h-12 w-40"></div></template>
                    <template x-if="quote">
                        <div>
                            <p class="font-display text-5xl" x-text="quote.formatted.total"></p>
                            <p class="mt-1 text-sm text-muted"><span x-text="quote.formatted.monthly"></span> {{ __('salon.ui.per_month') }} · <span x-text="cycleLabel()"></span></p>
                            <ul class="mt-6 space-y-2 border-t hairline pt-4 text-sm">
                                <template x-for="line in quote.lines" :key="line.description"><li class="flex justify-between gap-3"><span class="text-muted" x-text="line.description"></span><span x-text="luxe.money(line.amount, quote.currency)"></span></li></template>
                                <li x-show="quote.cycle_discount > 0" class="flex justify-between gap-3 text-success"><span>{{ __('platform.pricing.discount') }}</span><span x-text="'−' + quote.formatted.discount"></span></li>
                            </ul>
                        </div>
                    </template>
                    <a :href="`${signup}?plan=${plan}`" class="btn-primary mt-8">{{ __('platform.cta.start_trial') }}</a>
                    <p class="mt-3 text-center text-xs text-muted">{{ __('platform.hero.trust') }}</p>
                </div>
            </div>
        </div>
    </div>
</section>
@once
<script>
    window.luxePricing = function (cfg) {
        return {
            ...cfg,
            plan: (cfg.plans.find((p) => p.featured) || cfg.plans[0] || {}).slug,
            cycle: 12, branches: 1, extras: [], quote: null, ctrl: null,
            current() { return this.plans.find((p) => p.slug === this.plan) || {}; },
            included(key) { return (this.current().included || []).includes(key); },
            toggle(key) { this.extras = this.extras.includes(key) ? this.extras.filter((k) => k !== key) : [...this.extras, key]; this.refresh(); },
            cycleLabel() { return @js(__('platform.pricing.billed')).replace(':count', this.cycle); },
            async refresh() {
                if (!this.plan) return;
                this.ctrl?.abort(); this.ctrl = new AbortController();
                const q = new URLSearchParams({ plan: this.plan, cycle: this.cycle, branches: this.branches });
                this.extras.filter((k) => !this.included(k)).forEach((k) => q.append('modules[]', k));
                try {
                    const res = await fetch(`${this.url}?${q}`, { headers: { Accept: 'application/json' }, signal: this.ctrl.signal });
                    if (res.ok) this.quote = await res.json();
                } catch (e) { /* aborted */ }
            },
        };
    };
</script>
@endonce
