<x-layouts.site :title="__('ai_studio.title')" :description="__('ai_studio.meta')" :bare="$kiosk">
    <section @class(['relative', 'min-h-dvh bg-ivory' => $kiosk]) x-data="luxeStudio(@js([
        'consented' => (bool) $consent,
        'minor' => (bool) $consent?->is_minor,
        'styles' => $styles,
        'categories' => $categories,
        'quota' => $quota,
        'kiosk' => $kiosk,
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
                {{-- Step 1: consent --}}
                <div x-show="!consented" class="card mt-10 max-w-2xl p-6 sm:p-8">
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

                <div x-show="consented" class="mt-10">
                    {{-- Mode switch --}}
                    <div class="flex flex-wrap gap-2" role="tablist">
                        <button type="button" class="chip min-h-11" :class="mode === 'tryon' && 'chip-active'" @click="mode = 'tryon'" role="tab">{{ __('ai_studio.modes.tryon') }}</button>
                        <button type="button" class="chip min-h-11" :class="mode === 'consult' && 'chip-active'" @click="mode = 'consult'; framing = 'face'" role="tab" x-show="!minor">{{ __('ai_studio.modes.consult') }}</button>
                    </div>

                    <p x-show="minor" class="mt-4 rounded-xl bg-warning/10 px-4 py-3 text-sm text-warning">{{ __('ai_studio.consent.minor_notice') }}</p>

                    {{-- Step 2: style --}}
                    <div x-show="mode === 'tryon'" class="mt-8">
                        <div class="relative flex gap-2 overflow-x-auto pb-2">
                            <template x-for="cat in visibleCategories" :key="cat">
                                <button type="button" class="chip min-h-11 whitespace-nowrap" :class="category === cat && 'chip-active'" @click="category = cat" x-text="labels[cat]"></button>
                            </template>
                        </div>
                        <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                            <template x-for="s in stylesIn(category)" :key="s.id">
                                <button type="button" class="card-hover overflow-hidden text-start" :class="styleId === s.id && 'ring-2 ring-gold'" @click="pick(s)">
                                    <div class="aspect-[4/5] bg-gradient-to-br from-rose to-ivory">
                                        <template x-if="s.image"><img :src="s.image" :alt="s.name" loading="lazy" class="h-full w-full object-cover"></template>
                                        <template x-if="!s.image && s.swatch"><div class="flex h-full items-center justify-center"><span class="h-16 w-16 rounded-full shadow-lift" :style="`background:${s.swatch}`"></span></div></template>
                                    </div>
                                    <div class="p-3">
                                        <p class="text-sm font-medium" x-text="s.name"></p>
                                        <p class="text-xs text-muted" x-show="s.price"><span x-text="s.price"></span> · <span x-text="s.duration"></span> {{ __('salon.ui.minutes') }}</p>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>

                    {{-- Step 3: capture --}}
                    <div class="mt-10 grid gap-8 lg:grid-cols-[1.2fr_1fr]" x-show="mode === 'consult' || styleId">
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
                </div>
            @endif

            @if (! $kiosk && $consent)
                <form method="POST" action="{{ route('studio.consent.withdraw') }}" class="mt-12 text-xs text-muted">@csrf
                    <button class="underline underline-offset-4">{{ __('ai_studio.consent.withdraw') }}</button>
                    @if ($client) · <a class="underline underline-offset-4" href="{{ route('studio.data.export') }}">{{ __('ai_studio.privacy.export') }}</a>@endif
                </form>
            @endif
        </div>
    </section>

    @include('site.ai-studio.partials.script')
</x-layouts.site>
