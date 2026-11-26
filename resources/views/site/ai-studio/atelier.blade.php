<x-layouts.site :title="__('ai_studio.atelier.title')" :description="__('ai_studio.atelier.meta')">
    @include('site.ai-studio.partials.atelier-script')
    @vite(['resources/js/atelier-engine.js'])

    <section class="relative" x-data="luxeAtelier(@js($config + [
        'consented' => (bool) $consent,
        'minor' => (bool) $consent?->is_minor,
        'signedIn' => (bool) $client,
    ]))" x-cloak @keydown.escape.window="consentOpen = false">
        <div class="container-luxe pb-32 pt-10 sm:pb-20 sm:pt-14">
            {{-- Intro --}}
            <header class="max-w-2xl" data-reveal>
                <a href="{{ route('studio.index') }}" class="text-xs text-muted underline-offset-4 hover:underline"><span class="inline-block rtl:rotate-180">←</span> {{ __('ai_studio.title') }}</a>
                <p class="eyebrow mt-4">{{ __('ai_studio.atelier.eyebrow') }}</p>
                <h1 class="mt-3 text-4xl font-semibold leading-[1.1] sm:text-5xl">{{ __('ai_studio.atelier.headline') }}</h1>
                <p class="mt-4 text-lg text-muted">{{ __('ai_studio.atelier.subhead') }}</p>
                <ul class="mt-5 flex flex-wrap gap-2 text-xs text-muted">
                    <li class="chip min-h-8 px-3">● {{ __('ai_studio.atelier.badges.live') }}</li>
                    <li class="chip min-h-8 px-3">🔒 {{ __('ai_studio.atelier.badges.private') }}</li>
                    <li class="chip min-h-8 px-3" x-show="renderAvailable">✦ {{ __('ai_studio.atelier.badges.real') }}</li>
                </ul>
            </header>

            {{-- Category --}}
            <div class="mt-8 flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none]" role="tablist" aria-label="{{ __('ai_studio.atelier.categories_label') }}">
                <template x-for="c in categories" :key="c.key">
                    <button type="button" role="tab" class="chip min-h-11 shrink-0 px-5" :class="cat === c.key && 'chip-active'" :aria-selected="cat === c.key"
                            @click="setCategory(c.key)" x-text="i18n.categories[c.key]"></button>
                </template>
            </div>

            <div class="mt-6 grid gap-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] lg:gap-8">
                {{-- Stage (on phones it stays in view while the controls scroll under it) --}}
                <div class="contents lg:sticky lg:top-24 lg:block lg:self-start">
                    <div class="sticky top-[4.75rem] z-20 max-h-[46dvh] self-start select-none overflow-hidden rounded-(--radius-hero) bg-[#171513] shadow-soft transition-[aspect-ratio] duration-300 lg:static lg:max-h-none lg:shadow-none" :class="wide ? 'aspect-[4/3]' : 'aspect-[3/4]'" x-ref="stage"
                         @pointerdown="startSplit($event)" @pointermove="moveSplit($event)" @pointerup="endSplit()" @pointercancel="endSplit()"
                         :style="comparing && 'touch-action: none'">
                        <canvas x-ref="canvas" class="h-full w-full object-contain" role="img" :aria-label="summary"></canvas>
                        <video x-ref="video" playsinline muted class="hidden"></video>

                        {{-- Compare --}}
                        <template x-if="comparing && status === 'ready'">
                            <div class="pointer-events-none absolute inset-0">
                                <div class="absolute inset-y-0 w-0.5 bg-white/90 shadow" :style="`left: ${splitLeft}px`">
                                    <span class="absolute top-1/2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white px-2.5 py-1.5 text-xs font-medium text-ink shadow-lg">⟷</span>
                                </div>
                                <span class="absolute left-3 top-3 rounded-full bg-black/55 px-3 py-1 text-xs text-white">{{ __('ai_studio.atelier.compare.before') }}</span>
                                <span class="absolute right-3 top-3 rounded-full bg-black/55 px-3 py-1 text-xs text-white">{{ __('ai_studio.atelier.compare.after') }}</span>
                            </div>
                        </template>

                        {{-- Always-reachable view toggles (the stage stays pinned while the controls scroll) --}}
                        <div x-show="status === 'ready'" class="absolute bottom-3 end-3 z-10 flex gap-2" @pointerdown.stop>
                            <button type="button" class="grid size-11 place-items-center rounded-full bg-black/55 text-white backdrop-blur transition hover:bg-black/70" :class="zoom && 'ring-2 ring-gold'"
                                    @click="toggleZoom()" :aria-pressed="zoom" aria-label="{{ __('ai_studio.atelier.compare.zoom') }}" title="{{ __('ai_studio.atelier.compare.zoom') }}">
                                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5M11 8.5v5M8.5 11h5"/></svg>
                            </button>
                            <button type="button" class="grid size-11 place-items-center rounded-full bg-black/55 text-white backdrop-blur transition hover:bg-black/70" :class="comparing && 'ring-2 ring-gold'"
                                    @click="toggleCompare()" :aria-pressed="comparing" aria-label="{{ __('ai_studio.atelier.compare.toggle') }}" title="{{ __('ai_studio.atelier.compare.toggle') }}">
                                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3.5" y="4.5" width="17" height="15" rx="3"/><path d="M12 4.5v15"/><path d="M12 4.5h5.5a3 3 0 013 3v9a3 3 0 01-3 3H12z" fill="currentColor" fill-opacity=".35"/></svg>
                            </button>
                        </div>

                        {{-- Loading / errors / hints --}}
                        <div x-show="status === 'loading'" class="absolute inset-0 grid place-items-center bg-[#171513]/80 p-8 text-center text-white" x-transition.opacity>
                            <div>
                                <span class="mx-auto block size-10 animate-spin rounded-full border-2 border-white/25 border-t-gold"></span>
                                <p class="mt-4 font-display text-xl">{{ __('ai_studio.atelier.status.loading') }}</p>
                                <p class="mt-1 text-sm text-white/70">{{ __('ai_studio.atelier.status.loading_hint') }}</p>
                            </div>
                        </div>
                        <div x-show="status === 'error'" class="absolute inset-0 grid place-items-center bg-[#171513] p-8 text-center text-white">
                            <p class="max-w-xs text-sm" x-text="errorText || @js(__('ai_studio.atelier.status.error'))"></p>
                        </div>
                        <p x-show="status === 'ready' && !found" x-transition.opacity
                           class="absolute inset-x-4 top-4 rounded-xl bg-black/60 px-4 py-3 text-center text-sm text-white backdrop-blur"
                           x-text="framing === 'hand' ? i18n.noHand : i18n.noFace" role="status"></p>
                        <div x-show="busy === 'real' || busy === 'advice'" class="absolute inset-0 grid place-items-center bg-[#171513]/70 p-8 text-center text-white" x-transition.opacity>
                            <div>
                                <span class="mx-auto block size-10 animate-spin rounded-full border-2 border-white/25 border-t-gold"></span>
                                <p class="mt-4 font-display text-xl" x-text="statusText"></p>
                            </div>
                        </div>
                    </div>

                    {{-- Source + compare --}}
                    <div class="flex flex-wrap items-center gap-2 lg:mt-4">
                        <template x-for="s in samplesFor(framing)" :key="s.key">
                            <button type="button" class="size-12 shrink-0 overflow-hidden rounded-full border-2 transition" :class="sourceKind === 'sample' && sampleKey === s.key ? 'border-gold' : 'border-transparent opacity-80 hover:opacity-100'"
                                    @click="useSample(s)" :aria-label="i18n.sample" :aria-pressed="sourceKind === 'sample' && sampleKey === s.key">
                                <img :src="s.thumb" alt="" class="h-full w-full object-cover" loading="lazy">
                            </button>
                        </template>
                        <button type="button" class="chip min-h-11" :class="sourceKind === 'camera' && 'chip-active'" @click="sourceKind === 'camera' ? capture() : startCamera()">
                            <span x-text="sourceKind === 'camera' ? i18n.capture : i18n.camera"></span>
                        </button>
                        <button type="button" class="chip min-h-11" x-show="sourceKind === 'camera'" @click="flip()" :aria-label="i18n.flip">⟲</button>
                        <label class="chip min-h-11 cursor-pointer" :class="sourceKind === 'photo' && 'chip-active'">
                            <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="usePhoto($event)">
                            {{ __('ai_studio.atelier.source.upload') }}
                        </label>
                    </div>
                    <p class="text-xs text-muted lg:mt-3">{{ __('ai_studio.atelier.privacy') }}</p>
                </div>

                {{-- Controls --}}
                <div class="flex flex-col gap-6">
                    {{-- Lashes --}}
                    <div x-show="cat === 'lashes'" class="space-y-6">
                        <template x-if="fit">
                            <div class="card p-5" role="status">
                                <p class="eyebrow">{{ __('ai_studio.atelier.fit.title') }}</p>
                                <p class="mt-2 font-display text-xl" x-text="fitLine"></p>
                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <p class="text-sm text-muted"><span>{{ __('ai_studio.atelier.fit.suggestion') }}</span>: <span class="text-ink" x-text="suggestionLine"></span></p>
                                    <button type="button" class="btn-ghost min-h-9 px-4 text-xs" @click="apply('lashes', fit.suggest)">{{ __('ai_studio.atelier.fit.apply') }}</button>
                                </div>
                            </div>
                        </template>
                        <div>
                            <p class="label">{{ __('ai_studio.atelier.controls.style') }}</p>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                <template x-for="(s, key) in catalog.lashes.styles" :key="key">
                                    <button type="button" class="rounded-(--radius-card) border p-3 text-start transition" :class="design.lashes.style === key ? 'border-ink bg-ink text-ivory' : 'border-line bg-white hover:border-gold'"
                                            @click="set('style', key)" :aria-pressed="design.lashes.style === key">
                                        <svg viewBox="0 0 60 22" class="h-6 w-16" aria-hidden="true"><path :d="lashIcon(s)" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
                                        <span class="mt-1 block text-sm font-medium" x-text="s.label"></span>
                                        <span class="block text-xs opacity-70" x-text="s.desc"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <label class="block">
                                <span class="label flex justify-between"><span>{{ __('ai_studio.atelier.controls.length') }}</span><span class="text-ink" x-text="design.lashes.length + ' ' + i18n.mm"></span></span>
                                <input type="range" class="w-full accent-gold" :min="catalog.lashes.length.min" :max="catalog.lashes.length.max" step="1" :value="design.lashes.length" @input="set('length', +$event.target.value)">
                            </label>
                            <label class="block">
                                <span class="label flex justify-between"><span>{{ __('ai_studio.atelier.controls.fullness') }}</span><span class="text-ink" x-text="design.lashes.fullness + '%'"></span></span>
                                <input type="range" class="w-full accent-gold" :min="catalog.lashes.fullness.min" :max="catalog.lashes.fullness.max" step="5" :value="design.lashes.fullness" @input="set('fullness', +$event.target.value)">
                            </label>
                        </div>
                        <div>
                            <p class="label">{{ __('ai_studio.atelier.controls.curl') }}</p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="(c, key) in catalog.lashes.curls" :key="key">
                                    <button type="button" class="chip min-h-11" :class="design.lashes.curl === key && 'chip-active'" @click="set('curl', key)" x-text="c.label" :aria-pressed="design.lashes.curl === key"></button>
                                </template>
                            </div>
                        </div>
                        <div class="rounded-(--radius-card) border border-dashed hairline p-4">
                            <p class="label mb-0">{{ __('ai_studio.atelier.controls.lash_map') }}</p>
                            <p class="mt-1 font-display text-2xl tracking-wide" x-text="lashMap.join(' · ')" dir="ltr"></p>
                            <p class="text-xs text-muted">{{ __('ai_studio.atelier.controls.lash_map_hint') }}</p>
                        </div>
                    </div>

                    {{-- Brows --}}
                    <div x-show="cat === 'brows'" class="space-y-6">
                        <div>
                            <p class="label">{{ __('ai_studio.atelier.controls.shape') }}</p>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                <template x-for="(s, key) in catalog.brows.shapes" :key="key">
                                    <button type="button" class="rounded-(--radius-card) border p-3 text-start transition" :class="design.brows.shape === key ? 'border-ink bg-ink text-ivory' : 'border-line bg-white hover:border-gold'" @click="set('shape', key)" :aria-pressed="design.brows.shape === key">
                                        <svg viewBox="0 0 60 22" class="h-6 w-16" aria-hidden="true"><path :d="browIcon(s)" fill="currentColor"/></svg>
                                        <span class="mt-1 block text-sm font-medium" x-text="s.label"></span>
                                        <span class="block text-xs opacity-70" x-text="s.desc"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                        <div>
                            <p class="label">{{ __('ai_studio.atelier.controls.shade') }}: <span class="text-ink" x-text="catalog.brows.shades[design.brows.shade].label"></span></p>
                            <div class="flex flex-wrap gap-3">
                                <template x-for="(s, key) in catalog.brows.shades" :key="key">
                                    <button type="button" class="size-11 rounded-full ring-offset-2 transition" :class="design.brows.shade === key ? 'ring-2 ring-ink' : 'ring-1 ring-line'" :style="`background:${s.hex}`" @click="set('shade', key)" :aria-label="s.label" :aria-pressed="design.brows.shade === key"></button>
                                </template>
                            </div>
                        </div>
                        <label class="block">
                            <span class="label flex justify-between"><span>{{ __('ai_studio.atelier.controls.intensity') }}</span><span class="text-ink" x-text="design.brows.intensity + '%'"></span></span>
                            <input type="range" class="w-full accent-gold" min="20" max="100" step="5" :value="design.brows.intensity" @input="set('intensity', +$event.target.value)">
                        </label>
                    </div>

                    {{-- Lips --}}
                    <div x-show="cat === 'lips'" class="space-y-6">
                        <div>
                            <p class="label">{{ __('ai_studio.atelier.controls.shade') }}: <span class="text-ink" x-text="catalog.lips.shades[design.lips.shade].label"></span></p>
                            <div class="flex flex-wrap gap-3">
                                <template x-for="(s, key) in catalog.lips.shades" :key="key">
                                    <button type="button" class="size-11 rounded-full ring-offset-2 transition" :class="design.lips.shade === key ? 'ring-2 ring-ink' : 'ring-1 ring-line'" :style="`background:${s.hex}`" @click="set('shade', key)" :aria-label="s.label" :aria-pressed="design.lips.shade === key"></button>
                                </template>
                            </div>
                        </div>
                        <div>
                            <p class="label">{{ __('ai_studio.atelier.controls.finish') }}</p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="(f, key) in catalog.lips.finishes" :key="key">
                                    <button type="button" class="chip min-h-11" :class="design.lips.finish === key && 'chip-active'" @click="set('finish', key)" x-text="f.label" :aria-pressed="design.lips.finish === key"></button>
                                </template>
                            </div>
                        </div>
                        <label class="block">
                            <span class="label flex justify-between"><span>{{ __('ai_studio.atelier.controls.intensity') }}</span><span class="text-ink" x-text="design.lips.intensity + '%'"></span></span>
                            <input type="range" class="w-full accent-gold" min="20" max="100" step="5" :value="design.lips.intensity" @input="set('intensity', +$event.target.value)">
                        </label>
                    </div>

                    {{-- Nails --}}
                    <div x-show="cat === 'nails'" class="space-y-6">
                        <div>
                            <p class="label">{{ __('ai_studio.atelier.controls.colour') }}: <span class="text-ink" x-text="catalog.nails.colors[design.nails.color].label"></span></p>
                            <div class="flex flex-wrap gap-3">
                                <template x-for="(s, key) in catalog.nails.colors" :key="key">
                                    <button type="button" class="size-11 rounded-full ring-offset-2 transition" :class="design.nails.color === key ? 'ring-2 ring-ink' : 'ring-1 ring-line'" :style="`background:${s.hex}`" @click="set('color', key)" :aria-label="s.label" :aria-pressed="design.nails.color === key"></button>
                                </template>
                            </div>
                        </div>
                        <div>
                            <p class="label">{{ __('ai_studio.atelier.controls.shape') }}</p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="(s, key) in catalog.nails.shapes" :key="key">
                                    <button type="button" class="chip min-h-11" :class="design.nails.shape === key && 'chip-active'" @click="set('shape', key)" x-text="s.label" :aria-pressed="design.nails.shape === key"></button>
                                </template>
                            </div>
                        </div>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <p class="label">{{ __('ai_studio.atelier.controls.nail_length') }}</p>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="(s, key) in catalog.nails.lengths" :key="key">
                                        <button type="button" class="chip min-h-11" :class="design.nails.length === key && 'chip-active'" @click="set('length', key)" x-text="s.label" :aria-pressed="design.nails.length === key"></button>
                                    </template>
                                </div>
                            </div>
                            <div>
                                <p class="label">{{ __('ai_studio.atelier.controls.finish') }}</p>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="(s, key) in catalog.nails.finishes" :key="key">
                                        <button type="button" class="chip min-h-11" :class="design.nails.finish === key && 'chip-active'" @click="set('finish', key)" x-text="s.label" :aria-pressed="design.nails.finish === key"></button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- AI artist --}}
                    <div x-show="adviceAvailable" class="card p-5">
                        <template x-if="!advice">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <p class="font-medium">{{ __('ai_studio.atelier.advice.cta') }}</p>
                                    <p class="text-sm text-muted">{{ __('ai_studio.atelier.advice.hint') }}</p>
                                </div>
                                <button type="button" class="btn-ghost shrink-0" @click="askArtist()" :disabled="!!busy || status !== 'ready' || !found">✦ {{ __('ai_studio.atelier.advice.ask') }}</button>
                            </div>
                        </template>
                        <template x-if="advice">
                            <div>
                                <p class="eyebrow">{{ __('ai_studio.atelier.advice.title') }}</p>
                                <p class="mt-2 font-display text-xl" x-text="advice.summary"></p>
                                <p class="mt-1 text-sm text-muted" x-show="advice.features" x-text="advice.features"></p>
                                <p class="mt-2 text-sm" x-text="advice.reason"></p>
                                <div class="mt-4 flex gap-2">
                                    <button type="button" class="btn-primary" @click="apply(advice.category, advice.params); advice = null">{{ __('ai_studio.atelier.advice.apply') }}</button>
                                    <button type="button" class="btn-ghost" @click="advice = null">{{ __('ai_studio.atelier.advice.dismiss') }}</button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <p x-show="error" class="rounded-xl bg-danger/10 px-4 py-3 text-sm text-danger" x-text="error" role="alert"></p>
                    <p x-show="notice" class="rounded-xl bg-success/10 px-4 py-3 text-sm text-success" x-text="notice" role="status"></p>

                    {{-- Actions (sticky on phones) --}}
                    <div class="fixed inset-x-0 bottom-0 z-30 border-t hairline bg-ivory/95 px-4 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 backdrop-blur sm:static sm:border-0 sm:bg-transparent sm:p-0 sm:backdrop-blur-none">
                        <p class="mb-2 truncate text-xs text-muted sm:hidden" x-text="summary"></p>
                        <div class="flex gap-2">
                            <button type="button" class="btn-ghost px-4" @click="save()" :disabled="!!busy" :aria-label="i18n.save">
                                <span x-text="isSaved ? '♥' : '♡'"></span><span class="hidden sm:inline" x-text="isSaved ? i18n.saved : i18n.save"></span>
                            </button>
                            <button type="button" class="btn-ghost flex-1" x-show="booking" @click="book()" :disabled="!!busy">{{ __('ai_studio.atelier.actions.book') }}</button>
                            <button type="button" class="btn-primary flex-1" x-show="renderAvailable" @click="makeReal()" :disabled="!!busy || status !== 'ready' || !found">✦ {{ __('ai_studio.atelier.actions.real') }}</button>
                        </div>
                        <p class="mt-2 hidden text-xs text-muted sm:block" x-show="renderAvailable">{{ __('ai_studio.atelier.actions.real_hint') }}</p>
                    </div>
                </div>
            </div>

            {{-- Saved looks --}}
            <section class="mt-16" aria-labelledby="atelier-saved">
                <h2 id="atelier-saved" class="text-2xl font-semibold">{{ __('ai_studio.atelier.saved.title') }}</h2>
                <p x-show="!designs.length" class="mt-2 text-sm text-muted">{{ __('ai_studio.atelier.saved.empty') }}</p>
                <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <template x-for="d in designs" :key="d.reference">
                        <li class="card flex flex-col gap-3 p-4">
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 grid size-9 shrink-0 place-items-center rounded-full bg-ivory text-sm" x-text="icons[d.category]" aria-hidden="true"></span>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium" x-text="d.summary"></p>
                                    <p class="text-xs text-muted" x-show="d.price" x-text="d.price"></p>
                                </div>
                            </div>
                            <div class="mt-auto flex flex-wrap gap-2">
                                <button type="button" class="chip min-h-9" @click="tryDesign(d)">{{ __('ai_studio.atelier.saved.apply') }}</button>
                                <a class="chip min-h-9" x-show="d.book" :href="d.book">{{ __('ai_studio.atelier.saved.book') }}</a>
                                <button type="button" class="chip min-h-9 ms-auto text-muted" @click="remove(d)" :aria-label="i18n.remove">✕</button>
                            </div>
                        </li>
                    </template>
                </ul>
                <p class="mt-6 text-xs text-muted">{{ __('ai_studio.atelier.disclaimer') }}</p>
            </section>
        </div>

        {{-- Consent (only before the photo goes to the AI) --}}
        <div x-show="consentOpen" x-transition.opacity class="fixed inset-0 z-50 grid place-items-end bg-black/50 p-0 sm:place-items-center sm:p-6" @click.self="consentOpen = false" role="dialog" aria-modal="true" aria-labelledby="atelier-consent-title">
            <div class="max-h-[90dvh] w-full max-w-lg overflow-y-auto rounded-t-(--radius-hero) bg-ivory p-6 sm:rounded-(--radius-hero) sm:p-8">
                <p class="eyebrow">{{ __('ai_studio.atelier.consent_for') }}</p>
                <h2 id="atelier-consent-title" class="mt-2 text-2xl font-semibold">{{ __('ai_studio.consent.title') }}</h2>
                <ul class="mt-4 space-y-2 text-sm text-muted">
                    <li>✦ {{ __('ai_studio.consent.point_storage', ['days' => (int) $tenant->setting('ai.retention_days', config('ai.retention_days'))]) }}</li>
                    <li>✦ {{ __('ai_studio.privacy.no_training') }}</li>
                    <li>✦ {{ __('ai_studio.consent.point_providers') }}</li>
                    <li>✦ {{ __('ai_studio.consent.point_rights') }}</li>
                </ul>
                <form class="mt-6 space-y-4" @submit.prevent="giveConsent($event.target)">
                    <fieldset>
                        <legend class="label">{{ __('ai_studio.consent.age_question') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-3">
                            <label class="chip min-h-11 cursor-pointer"><input type="radio" name="adult" value="yes" required class="accent-ink"> {{ __('ai_studio.consent.adult_yes') }}</label>
                            <label class="chip min-h-11 cursor-pointer"><input type="radio" name="adult" value="no" class="accent-ink"> {{ __('ai_studio.consent.adult_no') }}</label>
                        </div>
                    </fieldset>
                    <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="accept" value="1" required class="mt-1 accent-ink"> <span>{{ __('ai_studio.consent.accept') }}</span></label>
                    <div class="flex gap-2">
                        <button class="btn-primary flex-1" :disabled="busy === 'consent'">{{ __('ai_studio.consent.continue') }}</button>
                        <button type="button" class="btn-ghost" @click="consentOpen = false">{{ __('ai_studio.atelier.cancel') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</x-layouts.site>
