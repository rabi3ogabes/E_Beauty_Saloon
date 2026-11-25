<x-layouts.site :title="__('ai_studio.title')" :description="__('ai_studio.meta')" :bare="$kiosk">
    <section @class(['relative', 'min-h-dvh bg-ivory' => $kiosk]) x-data="luxeStudio(@js([
        'consented' => (bool) $consent,
        'minor' => (bool) $consent?->is_minor,
        'styles' => $styles,
        'categories' => $categories,
        'quota' => $quota,
        'kiosk' => $kiosk,
        'tryOn' => $tryOnAvailable,
        'urls' => ['consent' => route('studio.consent'), 'tryOn' => route('studio.try-on'), 'consultant' => route('studio.consultant'), 'reset' => route('studio.kiosk', ['reset' => 1])],
        'i18n' => [
            'tooDark' => __('ai_studio.capture.too_dark'),
            'tooBright' => __('ai_studio.capture.too_bright'),
            'goodLight' => __('ai_studio.capture.good_light'),
            'noCamera' => __('ai_studio.capture.no_camera'),
            'failed' => __('ai_studio.errors.try_again'),
        ],
    ]))" x-cloak>
        <div @class(['container-luxe py-10 sm:py-16', 'max-w-5xl' => $kiosk])>
            {{-- Intro --}}
            <header class="max-w-2xl" data-reveal>
                <p class="eyebrow">{{ __('ai_studio.eyebrow') }}</p>
                <h1 @class(['mt-3 font-semibold leading-[1.1]', 'text-5xl' => $kiosk, 'text-4xl sm:text-5xl' => ! $kiosk])>{{ __('ai_studio.headline') }}</h1>
                <p class="mt-4 text-lg text-muted">{{ __('ai_studio.subhead') }}</p>
                @if (! $kiosk)
                    <p class="mt-4 text-sm text-muted">
                        @if ($quota['remaining'] > 0)
                            {{ trans_choice('ai_studio.quota.remaining', $quota['remaining'], ['count' => $quota['remaining']]) }}
                        @else
                            {{ __('ai_studio.quota.none') }}
                        @endif
                    </p>
                @endif
            </header>

            @if (session('status'))<p class="mt-6 rounded-xl bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</p>@endif
            @if ($errors->any())<p class="mt-6 rounded-xl bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</p>@endif

            @if ($styles->isEmpty())
                <div class="card mt-10 p-10 text-center">
                    <p class="font-display text-2xl">{{ __('ai_studio.empty.title') }}</p>
                    <p class="mx-auto mt-2 max-w-md text-muted">{{ __('ai_studio.empty.body') }}</p>
                    @if (tenant()->hasModule('booking'))<a href="{{ url('/book') }}" class="btn-primary mt-6">{{ __('site.cta.book_now') }}</a>@endif
                </div>
            @else
                {{-- Mode switch --}}
                <div class="mt-10 flex flex-wrap gap-2" role="tablist">
                    <button type="button" class="chip min-h-11" :class="mode === 'tryon' && 'chip-active'" @click="mode = 'tryon'" role="tab" :aria-selected="mode === 'tryon'">{{ __('ai_studio.modes.tryon') }}</button>
                    <button type="button" class="chip min-h-11" :class="mode === 'consult' && 'chip-active'" @click="mode = 'consult'; framing = 'face'; wantCamera()" role="tab" :aria-selected="mode === 'consult'" x-show="!minor">{{ __('ai_studio.modes.consult') }}</button>
                </div>

                <p x-show="minor" class="mt-4 rounded-xl bg-warning/10 px-4 py-3 text-sm text-warning">{{ __('ai_studio.consent.minor_notice') }}</p>

                {{-- Lookbook: anyone can browse; consent is only asked for a try-on. --}}
                <div x-show="mode === 'tryon'" class="mt-8">
                    <div class="max-w-2xl">
                        <h2 class="text-2xl font-semibold sm:text-3xl">{{ __('ai_studio.lookbook.title') }}</h2>
                        <p class="mt-1 text-sm text-muted" x-text="tryOn ? @js(__('ai_studio.lookbook.subtitle')) : @js(__('ai_studio.lookbook.subtitle_book'))"></p>
                    </div>
                    <div class="relative -mx-4 mt-5 flex gap-2 overflow-x-auto px-4 pb-2 [scrollbar-width:none]" role="tablist">
                        <template x-for="cat in visibleCategories" :key="cat">
                            <button type="button" class="chip min-h-11 whitespace-nowrap" :class="category === cat && 'chip-active'" @click="category = cat" role="tab" :aria-selected="category === cat" x-text="labels[cat]"></button>
                        </template>
                    </div>
                    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
                        <template x-for="s in stylesIn(category)" :key="s.id">
                            <button type="button" class="card-hover group overflow-hidden text-start focus-visible:ring-2 focus-visible:ring-gold" :class="styleId === s.id && 'ring-2 ring-gold'" @click="openViewer(s, $el)" :aria-label="`${s.name} — ${@js(__('ai_studio.lookbook.view'))}`">
                                <div class="relative aspect-[4/5] overflow-hidden bg-gradient-to-br from-rose to-ivory">
                                    <template x-if="s.thumb"><img :src="s.thumb" :alt="s.name" loading="lazy" decoding="async" class="h-full w-full object-cover transition duration-700 ease-out group-hover:scale-[1.04]"></template>
                                    <template x-if="!s.thumb && s.swatch"><div class="flex h-full items-center justify-center"><span class="h-16 w-16 rounded-full shadow-lift" :style="`background:${s.swatch}`"></span></div></template>
                                    <span x-show="s.images.length > 1" class="glass absolute bottom-2.5 start-2.5 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium">
                                        <svg viewBox="0 0 20 20" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="5" width="11" height="11" rx="2"/><path d="M7 3h8a2 2 0 0 1 2 2v8"/></svg>
                                        <span x-text="s.images.length"></span>
                                    </span>
                                    <span x-show="styleId === s.id" class="absolute top-2.5 end-2.5 flex h-7 w-7 items-center justify-center rounded-full bg-gold text-ink shadow-lift" aria-hidden="true">✓</span>
                                </div>
                                <div class="p-3">
                                    <p class="text-sm font-medium" x-text="s.name"></p>
                                    <p class="mt-0.5 text-xs text-muted" x-show="s.price"><span x-text="s.price"></span><span x-show="s.duration"> · <span x-text="s.duration"></span> {{ __('salon.ui.minutes') }}</span></p>
                                </div>
                            </button>
                        </template>
                    </div>
                </div>

                <div x-show="!consented && wantsCamera" x-ref="consent" x-transition.opacity class="card mt-10 max-w-2xl scroll-mt-24 p-6 sm:p-8">
                    <p class="eyebrow" x-show="selected">{{ __('ai_studio.lookbook.consent_first') }}</p>
                    <h2 class="text-2xl font-semibold">{{ __('ai_studio.consent.title') }}</h2>
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
                        <button class="btn-primary" :disabled="busy">{{ __('ai_studio.consent.continue') }}</button>
                    </form>
                </div>

                {{-- Step 3: capture --}}
                <div x-ref="capture" class="mt-10 grid scroll-mt-24 gap-8 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]" x-show="consented && (mode === 'consult' || styleId)">
                    <div class="relative aspect-[3/4] overflow-hidden rounded-(--radius-hero) bg-ink">
                        <video x-ref="video" playsinline muted class="h-full w-full object-cover" :class="facing === 'user' && '-scale-x-100'" x-show="streaming"></video>
                        <img x-show="preview && !streaming" :src="preview" alt="" class="h-full w-full object-cover">
                        {{-- framing overlay --}}
                        <svg x-show="streaming" viewBox="0 0 300 400" class="pointer-events-none absolute inset-0 h-full w-full" aria-hidden="true">
                            <defs><mask id="frame-mask"><rect width="300" height="400" fill="white"/>
                                <ellipse x-show="framing === 'face'" cx="150" cy="185" rx="92" ry="125" fill="black"/>
                                <rect x-show="framing === 'hand'" x="55" y="90" width="190" height="240" rx="60" fill="black"/>
                                <rect x-show="framing === 'foot'" x="70" y="70" width="160" height="270" rx="70" fill="black"/>
                            </mask></defs>
                            <rect width="300" height="400" fill="rgba(20,16,20,.55)" mask="url(#frame-mask)"/>
                        </svg>
                        <div x-show="!streaming && !preview" class="absolute inset-0 flex flex-col items-center justify-center gap-4 p-6 text-center text-ivory">
                            <p class="font-display text-2xl">{{ __('ai_studio.capture.title') }}</p>
                            <p class="max-w-xs text-sm text-ivory/70" x-text="framing === 'hand' ? @js(__('ai_studio.capture.hint_hand')) : (framing === 'foot' ? @js(__('ai_studio.capture.hint_foot')) : @js(__('ai_studio.capture.hint_face')))"></p>
                        </div>
                        <p x-show="streaming && light" class="glass absolute top-4 start-4 rounded-full px-3 py-1.5 text-xs" x-text="light"></p>
                        <div x-show="busy" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-ink/60 text-ivory">
                            <div class="skeleton h-2 w-40 opacity-60"></div>
                            <p class="text-sm" x-text="statusText"></p>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3">
                        <template x-if="selected">
                            <div class="card p-5">
                                <p class="eyebrow" x-text="labels[selected.category]"></p>
                                <p class="mt-1 font-display text-xl" x-text="selected.name"></p>
                                <p class="mt-1 text-sm text-muted" x-text="selected.description"></p>
                            </div>
                        </template>
                        <button type="button" class="btn-primary" @click="startCamera()" x-show="!streaming" :disabled="busy">{{ __('ai_studio.capture.use_camera') }}</button>
                        <button type="button" class="btn-gold" @click="snap()" x-show="streaming">{{ __('ai_studio.capture.take') }}</button>
                        <button type="button" class="btn-ghost" @click="flip()" x-show="streaming">{{ __('ai_studio.capture.flip') }}</button>
                        <label class="btn-ghost cursor-pointer" x-show="!streaming">
                            {{ __('ai_studio.capture.upload') }}
                            <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="choose($event)">
                        </label>
                        <button type="button" class="btn-primary" x-show="file && !streaming" :disabled="busy" @click="submit()" x-text="mode === 'consult' ? @js(__('ai_studio.modes.consult_cta')) : @js(__('ai_studio.capture.generate'))"></button>
                        <p x-show="error" class="rounded-xl bg-danger/10 px-4 py-3 text-sm text-danger" x-text="error" role="alert"></p>

                        @if ($quota['remaining'] <= 0 && $pricePerLook > 0 && ! $kiosk)
                            <form method="POST" action="{{ route('studio.purchase') }}" class="card p-5">
                                @csrf
                                <p class="text-sm">{{ __('ai_studio.quota.buy', ['price' => money($pricePerLook)]) }}</p>
                                <div class="mt-3 flex gap-2"><input type="number" name="looks" min="1" max="20" value="3" class="input w-24"><button class="btn-gold">{{ __('ai_studio.quota.buy_cta') }}</button></div>
                            </form>
                        @endif
                        <p class="text-xs text-muted">{{ __('ai_studio.privacy.footnote') }}</p>
                    </div>
                </div>
            @endif

            @if (! $kiosk && $consent)
                <form method="POST" action="{{ route('studio.consent.withdraw') }}" class="mt-12 text-xs text-muted">@csrf
                    <button class="underline underline-offset-4">{{ __('ai_studio.consent.withdraw') }}</button>
                    @if ($client) · <a class="underline underline-offset-4" href="{{ route('studio.data.export') }}">{{ __('ai_studio.privacy.export') }}</a>@endif
                </form>
            @endif
        </div>

        {{-- Look viewer: swipe through example photos, then try it on or book it. --}}
        <template x-teleport="body">
            <div x-show="viewer" x-cloak x-transition.opacity.duration.250ms
                 class="fixed inset-0 z-[80] flex items-end justify-center bg-ink/75 backdrop-blur-sm sm:items-center sm:p-6"
                 role="dialog" aria-modal="true" :aria-label="viewer?.name" tabindex="-1"
                 @click.self="closeViewer()" @keydown.escape.window="viewer && closeViewer()"
                 @keydown.arrow-left.window="viewer && stepPhoto(rtl ? 1 : -1)" @keydown.arrow-right.window="viewer && stepPhoto(rtl ? -1 : 1)"
                 @keydown.tab="trapFocus($event)">
                <template x-if="viewer">
                    <div x-ref="dialog" x-show="viewer" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-6 opacity-0 sm:translate-y-0 sm:scale-[.98]" x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
                         class="relative grid max-h-[100dvh] w-full max-w-5xl overflow-y-auto rounded-t-[28px] bg-ivory shadow-lift sm:max-h-[92dvh] sm:rounded-[28px] lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)] lg:overflow-hidden">
                        {{-- Photos --}}
                        <div class="relative bg-ink">
                            <div x-ref="track" class="flex aspect-[4/5] max-h-[64dvh] w-full snap-x snap-mandatory overflow-x-auto overscroll-x-contain [scrollbar-width:none] lg:max-h-[92dvh] [&::-webkit-scrollbar]:hidden" @scroll.debounce.60ms="syncPhoto()">
                                <template x-for="(src, i) in viewer.images" :key="src">
                                    <div class="relative h-full w-full shrink-0 snap-center">
                                        <img :src="src" :alt="`${viewer.name} — ${photoLabel(i)}`" :loading="i === 0 ? 'eager' : 'lazy'" decoding="async" class="h-full w-full object-cover">
                                    </div>
                                </template>
                                <template x-if="!viewer.images.length">
                                    <div class="flex h-full w-full shrink-0 flex-col items-center justify-center gap-4 bg-gradient-to-br from-rose to-ivory text-ink">
                                        <span x-show="viewer.swatch" class="h-24 w-24 rounded-full shadow-lift" :style="`background:${viewer.swatch}`"></span>
                                        <p class="text-sm text-muted">{{ __('ai_studio.lookbook.no_photo') }}</p>
                                    </div>
                                </template>
                            </div>
                            <button type="button" x-ref="closeBtn" @click="closeViewer()" class="glass absolute top-3 end-3 flex h-11 w-11 items-center justify-center rounded-full text-ink" aria-label="{{ __('ai_studio.lookbook.close') }}">
                                <svg viewBox="0 0 20 20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15"/></svg>
                            </button>
                            <template x-if="viewer.images.length > 1">
                                <div>
                                    <button type="button" @click="stepPhoto(-1)" :disabled="photo === 0" class="glass absolute top-1/2 start-3 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full text-ink transition disabled:opacity-0 sm:flex" aria-label="{{ __('ai_studio.lookbook.prev') }}">
                                        <svg viewBox="0 0 20 20" class="h-5 w-5 rtl:rotate-180" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12.5 4.5L7 10l5.5 5.5"/></svg>
                                    </button>
                                    <button type="button" @click="stepPhoto(1)" :disabled="photo === viewer.images.length - 1" class="glass absolute top-1/2 end-3 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full text-ink transition disabled:opacity-0 sm:flex" aria-label="{{ __('ai_studio.lookbook.next') }}">
                                        <svg viewBox="0 0 20 20" class="h-5 w-5 rtl:rotate-180" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7.5 4.5L13 10l-5.5 5.5"/></svg>
                                    </button>
                                    <div class="absolute inset-x-0 bottom-3 flex justify-center gap-1.5" aria-hidden="true">
                                        <template x-for="(src, i) in viewer.images" :key="'dot' + i">
                                            <span class="h-1.5 rounded-full bg-ivory transition-all duration-300" :class="i === photo ? 'w-5 opacity-100' : 'w-1.5 opacity-60'"></span>
                                        </template>
                                    </div>
                                    <p class="sr-only" aria-live="polite" x-text="photoLabel(photo)"></p>
                                </div>
                            </template>
                        </div>

                        {{-- Details --}}
                        <div class="flex flex-col p-6 sm:p-8 lg:overflow-y-auto">
                            <p class="eyebrow" x-text="labels[viewer.category]"></p>
                            <h2 class="mt-2 font-display text-3xl leading-tight sm:text-4xl" x-text="viewer.name"></h2>
                            <p class="mt-3 text-muted" x-show="viewer.description" x-text="viewer.description"></p>

                            <dl class="mt-5 flex flex-wrap gap-2 text-sm" x-show="viewer.price || viewer.duration">
                                <div class="chip" x-show="viewer.price"><dt class="sr-only">{{ __('fields.price') }}</dt><dd x-text="@js(__('ai_studio.lookbook.from', ['price' => '__P__'])).replace('__P__', viewer.price)"></dd></div>
                                <div class="chip" x-show="viewer.duration"><dt class="sr-only">{{ __('fields.duration') }}</dt><dd x-text="@js(__('ai_studio.lookbook.minutes', ['n' => '__N__'])).replace('__N__', viewer.duration)"></dd></div>
                            </dl>

                            <div class="mt-6 flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none]" x-show="viewer.images.length > 1">
                                <template x-for="(src, i) in viewer.images" :key="'th' + i">
                                    <button type="button" @click="goPhoto(i)" class="h-16 w-14 shrink-0 overflow-hidden rounded-xl ring-offset-2 ring-offset-ivory transition" :class="i === photo ? 'ring-2 ring-gold' : 'opacity-70 hover:opacity-100'" :aria-label="photoLabel(i)" :aria-current="i === photo">
                                        <img :src="src" alt="" loading="lazy" class="h-full w-full object-cover">
                                    </button>
                                </template>
                            </div>

                            <div class="sticky bottom-0 -mx-6 mt-auto border-t border-line/60 bg-ivory/95 px-6 pt-4 pb-[max(1rem,env(safe-area-inset-bottom))] backdrop-blur sm:-mx-8 sm:px-8 lg:static lg:mx-0 lg:border-0 lg:bg-transparent lg:px-0 lg:pt-8 lg:pb-0 lg:backdrop-blur-none">
                                <div class="grid grid-cols-2 gap-3 lg:grid-cols-1" :class="(!viewer.book || !tryOn) && 'grid-cols-1'">
                                    <button type="button" class="btn-primary h-12" x-show="tryOn" @click="tryFromViewer()">{{ __('ai_studio.lookbook.try_on') }}</button>
                                    <a x-show="viewer.book" :href="viewer.book" class="h-12" :class="tryOn ? 'btn-ghost' : 'btn-primary'">{{ __('ai_studio.lookbook.book') }}</a>
                                </div>
                                <p class="mt-3 text-center text-[11px] text-muted lg:text-start" x-show="viewer.illustrative">{{ __('ai_studio.lookbook.illustrative') }}</p>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </section>

    @include('site.ai-studio.partials.script')
</x-layouts.site>
